<#
.SYNOPSIS
  Deploy pc-builder to Vercel, defaulting to a SAFE preview.

.DESCRIPTION
  Safety model, after an incident on 2026-09-28:

    `vercel deploy --prod=false` still produced a PRODUCTION deployment that
    took over the pctechguy.app aliases, and the old version of this script
    then re-pointed the live domain at "the latest Ready production
    deployment" with no confirmation. Two independent steps, one accidental
    production release.

  So this script now:

    * DEFAULTS TO PREVIEW. A bare run only creates a preview deployment and
      never touches a production alias.
    * Requires an EXPLICIT deployment URL to promote. It will never guess
      "the latest" and it will never promote on your behalf.
    * Refuses to promote a deployment that is not a production deployment.
    * Refuses to promote if the live site is already serving that same URL
      (no-op promotion) and says so, rather than pretending to do work.
    * Always verifies the live domains afterwards and reports the status.

  Usage:
      powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1
          Create a preview deployment. Safe. No alias changes.

      powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1 `
          -Promote https://pc-builder-xxxxxxxxxx-pctg.vercel.app
          Promote that SPECIFIC production deployment to pctechguy.app.

      powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1 -Inspect
          List recent deployments and aliases. Read-only.

      powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1 -Production
          Deploy straight to production, then VERIFY. If the verification gate
          fails, the previous known-good deployment is re-aliased automatically
          and this script exits non-zero.

.PARAMETER Promote
  The full https://*.vercel.app URL of the production deployment to move the
  live domains onto. Required for any production alias change.

.PARAMETER Inspect
  Read-only. List recent deployments and current aliases, then exit.

.PARAMETER Production
  Deploy to production, verify, and roll back automatically on failure.
#>

[CmdletBinding()]
param(
    [string]$Promote,
    [switch]$Inspect,
    [switch]$Production
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $repoRoot

$vercel = Join-Path $env:APPDATA 'npm\vercel.cmd'
if (-not (Test-Path -LiteralPath $vercel)) {
    $vercel = (Get-Command vercel.cmd -ErrorAction SilentlyContinue).Source
}
if (-not $vercel) {
    Write-Error 'vercel CLI not found. Install it with: npm i -g vercel'
}

# The npm vercel.cmd shim is a batch file that shells out to bare "node", which
# only resolves when node is on PATH. It is frequently NOT on PATH in the shells
# that run this script (including a child powershell -File invocation), and the
# shim's failure is a bare "node is not recognized" that gives no hint about the
# real problem. So call node.exe and the CLI entry point directly.
$nodeExe = (Get-Command node.exe -ErrorAction SilentlyContinue).Source
if (-not $nodeExe) {
    foreach ($cand in @(
            "$env:ProgramFiles\nodejs\node.exe",
            "${env:ProgramFiles(x86)}\nodejs\node.exe")) {
        if (Test-Path -LiteralPath $cand) { $nodeExe = $cand; break }
    }
}
$vercelJs = Join-Path $env:APPDATA 'npm\node_modules\vercel\dist\vc.js'

if ($nodeExe -and (Test-Path -LiteralPath $vercelJs)) {
    Write-Verbose "using node directly: $nodeExe $vercelJs"
} elseif (-not $nodeExe) {
    Write-Error 'node.exe not found. The vercel shim cannot run without it.'
}

# Run vercel and return trimmed stdout, keeping the exit code separately.
function Invoke-Vercel {
    param([string[]]$Arguments)

    # A native command that writes ANYTHING to stderr - and the Vercel CLI
    # always writes its version banner there - is turned into a terminating
    # error by $ErrorActionPreference = 'Stop' the moment it is redirected with
    # 2>&1. That made a perfectly successful deploy look like a crash. Drop to
    # 'Continue' for the duration of the call and judge success by exit code.
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($nodeExe -and (Test-Path -LiteralPath $vercelJs)) {
            $out = & $nodeExe $vercelJs @Arguments 2>&1 | Out-String
        } else {
            $out = & $vercel @Arguments 2>&1 | Out-String
        }
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }

    return [pscustomobject]@{
        ExitCode = $code
        Output   = $out
    }
}

# The domains that must never move without an explicit -Promote.
$liveDomains = @('https://pctechguy.app', 'https://www.pctechguy.app')

# Sample ONE real component image from the local cache, once, at the top level,
# because BOTH the deploy gate and the promote verification need it.
#
# Added 2026-09-30 after the gate passed a deployment that was serving every
# component photo as a 404. It checked a placeholder SVG and a stylesheet but
# never a product photo, so www.pctechguy.app sat two days on a stale
# deployment and reported itself perfectly healthy: every HTML route returned
# 200 while every /img/components/*.jpg 404'd. A gate that cannot see the thing
# you just released is not a gate.
#
# Sampled rather than hard-coded so it can never go stale or assert on a
# component that no longer exists.
$imgSample = Get-ChildItem -LiteralPath (Join-Path $PSScriptRoot '..\public\img\components') -Filter '*.jpg' -ErrorAction SilentlyContinue |
    Get-Random -Count 1
if ($imgSample) {
    Write-Host "component image check: $($imgSample.Name)" -ForegroundColor Cyan
} else {
    Write-Warning 'no component image found in the cache; gates cannot verify product photos'
}

# ------------------------------------------------------- preflight gates ---
# Run the mechanical checks BEFORE any deploy. Every gate here corresponds to a
# real error made on 2026-09-28 (phantom gate path, read-but-never-applied
# production env, unarchived uploads, secret-leaking local packer, invalid
# vercel.json keys). This is deliberately machine-enforced: on that day the same
# mistakes were written into AGENTS.md as prose rules and were then made anyway
# in the same session. Attention is not a control.
$preflight = 'scripts\genie-preflight.php'
if (Test-Path -LiteralPath $preflight) {
    Write-Host '=== preflight ===' -ForegroundColor Cyan
    $pfOut = & php $preflight 2>&1 | Out-String
    $pfCode = $LASTEXITCODE
    ($pfOut -split "`r?`n" | Where-Object { $_ -match 'FAIL|WARN|failures|PREFLIGHT' }) |
        ForEach-Object { Write-Host $_ }
    if ($pfCode -ne 0) {
        Write-Error 'preflight FAILED - refusing to deploy. Fix the failures above first.'
    }
    Write-Host 'preflight passed.' -ForegroundColor Green
} else {
    Write-Error "preflight script not found at $preflight - refusing to deploy unguarded."
}

# ------------------------------------------------------------------ archive ---
# We upload ONE tarball, not ~3,900 individual files.
#
# This is not an optimisation, it is a hard requirement. The free plan caps upload
# REQUESTS at 5,000 per rolling 24 hours ("code: api-upload-free"). This project has
# 3,290 component images in it, so a single loose-file deploy spends 66% of the
# day's entire upload budget and a second one fails outright - which is exactly what
# happened, and it is why the image release is stalled rather than shipped.
#
# `--archive=tgz` makes the CLI pack the deployable tree LOCALLY - applying
# .vercelignore, so .env, .env.production.neon, vendor/ and node_modules/ stay out -
# and then upload that single file.
#
# Deliberately NOT hand-rolling this with tar.exe: a local packer that forgets to
# apply .vercelignore would write .env.production.neon (the Neon database password)
# into an archive on disk. The CLI owns this correctly, so the CLI does it.
function Get-ArchiveArgs {
    return @('--archive=tgz')
}

if ($Inspect) {
    Write-Host '=== recent deployments ===' -ForegroundColor Cyan
    Invoke-Vercel @('ls') | ForEach-Object { Write-Host $_.Output }

    Write-Host '=== current aliases on the live domains ===' -ForegroundColor Cyan
    foreach ($domain in $liveDomains) {
        $r = Invoke-Vercel @('alias', 'ls')
        if ($r.ExitCode -ne 0) {
            Write-Warning "could not list aliases: $($r.Output)"
            break
        }
        Write-Host $r.Output
        break
    }
    return
}

# ------------------------------------------------------ production + gate ---
if ($Production) {
    if ($Promote) {
        Write-Error 'use either -Production or -Promote, not both.'
    }

    # Capture the CURRENT known-good deployment first, so a failed gate can be
    # undone. This value is the whole point: a production deploy cannot be
    # dry-run, and Vercel assigns the production aliases during the deploy
    # itself, so the only defence is knowing exactly where to go back to.
    $previous = (Invoke-Vercel @('inspect', 'https://pctechguy.app'))
    $rollbackUrl = $null
    if ($previous.Output -match 'url\s+(https://[a-z0-9][a-z0-9-]*-pctg\.vercel\.app)') {
        $rollbackUrl = $Matches[1]
        Write-Host "Rollback target captured: $rollbackUrl" -ForegroundColor Cyan
    } else {
        Write-Error 'Could not determine the current live deployment. Refusing to deploy without a rollback path.'
    }

    Write-Host 'Mode: PRODUCTION DEPLOY + VERIFY + AUTO-ROLLBACK' -ForegroundColor Yellow
    $d = Invoke-Vercel (@('deploy', '--target=production', '--yes') + (Get-ArchiveArgs))
    if ($d.ExitCode -ne 0) {
        # A failed build/deploy should not have taken the alias, but never
        # assume that - confirm production is still healthy.
        Write-Error "production deploy failed:`n$($d.Output)"
    }

    $newUrl = if ($d.Output -match 'https://[a-z0-9][a-z0-9-]*\.vercel\.app') { $Matches[0] } else { $null }
    if (-not $newUrl) {
        Write-Error "could not determine the new deployment URL from:`n$($d.Output)"
    }
    Write-Host "New production deployment: $newUrl" -ForegroundColor Green

    $info = Invoke-Vercel @('inspect', $newUrl)
    if ($info.Output -notmatch 'target\s+production') {
        Write-Error "new deployment does not report target=production:`n$($info.Output)"
    }
    if ($info.Output -notmatch 'Ready') {
        Write-Error "new deployment is not Ready:`n$($info.Output)"
    }

    # The gate. These are the exact assets that were 404 in production before
    # the vercel.json glob fix, plus the pages themselves.
    $gate = @(
        '/', '/builder', '/builder/bands',
        '/img/placeholders/gpu.svg',
        '/css/filament/filament/app.css',
        '/js/filament/filament/app.js',
        '/build/manifest.json'
    )

    # A REAL component image, sampled from the local cache at the top of this
    # script. See the comment there for why the gate needs one.
    if ($imgSample) {
        $gate += "/img/components/$($imgSample.BaseName).jpg"
    }

    $failed = @()
    foreach ($domain in $liveDomains) {
        foreach ($path in $gate) {
            $code = & curl.exe -s -o NUL -w "%{http_code}" --max-time 60 "$domain$path" 2>$null
            $ok = ($code -eq '200')
            if (-not $ok) { $failed += "$domain$path -> $code" }
            $colour = if ($ok) { 'Green' } else { 'Red' }
            $verdict = if ($ok) { 'ok' } else { 'FAILED' }
            Write-Host ("  {0,-6} {1,-46} {2}" -f $code, "$domain$path", $verdict) -ForegroundColor $colour
        }
    }

    if ($failed.Count -gt 0) {
        Write-Host ''
        # NOTE: Write-Error has NO -ForegroundColor parameter. Passing it there
        # raised a parameter-binding error, which aborted this script BEFORE the
        # rollback ran - disabling the safety net at the precise moment it was
        # needed. Colour goes through Write-Host; Write-Error only raises.
        Write-Host "VERIFICATION GATE FAILED - rolling back to $rollbackUrl" -ForegroundColor Red
        foreach ($domain in $liveDomains) {
            $host2 = ([uri]$domain).Host
            $rb = Invoke-Vercel @('alias', 'set', $rollbackUrl, $host2)
            if ($rb.ExitCode -ne 0) {
                Write-Error "ROLLBACK FAILED for $host2 - point it at $rollbackUrl by hand:`n$($rb.Output)"
            } else {
                Write-Host "  rolled back $host2 -> $rollbackUrl" -ForegroundColor Yellow
            }
        }
        Write-Error "rolled back. Failures:`n$($failed -join "`n")"
    }

    # `vercel deploy --prod` assigns the APEX alias. It does NOT move
    # www.pctechguy.app - measured twice on 2026-09-30, both times leaving www
    # silently one deployment behind while the verification gate passed it
    # cleanly, because a stale domain is a perfectly healthy domain serving
    # slightly older code.
    #
    # The gate above cannot catch that: it asks "does www serve the right
    # things?", not "is www serving THIS deployment?". The second question is
    # the one that matters, so ask it directly and move anything that drifted.
    Write-Host ''
    Write-Host 'Checking both live domains actually point at this deployment...' -ForegroundColor Cyan
    $drifted = @()
    foreach ($domain in $liveDomains) {
        $current = Invoke-Vercel @('inspect', $domain)
        if ($current.Output -match [regex]::Escape($newUrl)) {
            Write-Host "  $domain is current" -ForegroundColor DarkGray
        } else {
            $found = if ($current.Output -match 'Fetched deployment "([^"]+)"') { $Matches[1] } else { 'unknown' }
            Write-Host "  $domain is STALE (serving: $found)" -ForegroundColor Yellow
            $drifted += $domain
        }
    }
    if ($drifted.Count -gt 0) {
        Write-Host "Moving $($drifted.Count) stale domain(s) onto $newUrl" -ForegroundColor Yellow
        foreach ($domain in $drifted) {
            $domainHost = ([uri]$domain).Host
            $a = Invoke-Vercel @('alias', 'set', $newUrl, $domainHost)
            if ($a.ExitCode -ne 0) {
                Write-Error "alias set failed for $domainHost :`n$($a.Output)"
            } else {
                Write-Host "  aliased $domainHost -> $newUrl" -ForegroundColor Green
            }
        }
    }

    # Re-verify after moving, so "deployed and verified" can never mean
    # "verified before the domains were corrected".
    $postFail = @()
    foreach ($domain in $liveDomains) {
        $check = Invoke-Vercel @('inspect', $domain)
        if ($check.Output -notmatch [regex]::Escape($newUrl)) {
            $postFail += $domain
        }
    }
    if ($postFail.Count -gt 0) {
        Write-Error "these domains are not serving $newUrl after aliasing: $($postFail -join ', ')"
    }

    Write-Host ''
    Write-Host "PRODUCTION DEPLOYED AND VERIFIED: $newUrl" -ForegroundColor Green
    return
}

# ---------------------------------------------------------------- preview ---
if (-not $Promote) {
    Write-Host 'Mode: PREVIEW (no production alias will be touched)' -ForegroundColor Green

    # --target=preview is stated explicitly on purpose. This project has no Git
    # link, so `vercel deploy` is a CLI upload and its default target must never
    # be left to inference.
    $r = Invoke-Vercel (@('deploy', '--target=preview', '--yes') + (Get-ArchiveArgs))
    if ($r.ExitCode -ne 0) {
        Write-Error "preview deploy failed:`n$($r.Output)"
    }

    $url = if ($r.Output -match 'https://[a-z0-9][a-z0-9-]*\.vercel\.app') { $Matches[0] } else { $null }
    if (-not $url) {
        Write-Error "could not determine the preview URL from:`n$($r.Output)"
    }

    Write-Host ''
    Write-Host "Preview:  $url" -ForegroundColor Green
    Write-Host "Live site untouched: $($liveDomains[0])" -ForegroundColor Green

    $r2 = Invoke-Vercel @('inspect', $url)
    if ($r2.Output -match 'target\s+(\S+)') {
        $target = $Matches[1]
        if ($target -ne 'preview') {
            Write-Error "SAFETY ABORT: that deployment reports target='$target', not 'preview'. Do not promote it. Full output:`n$($r2.Output)"
        }
        Write-Host "Verified target: $target" -ForegroundColor Green
    }
    return
}

# -------------------------------------------------------------- promotion ---
if ($Promote -notmatch '^https://[a-z0-9][a-z0-9-]*\.vercel\.app/?$') {
    Write-Error "-Promote must be a single https://*.vercel.app deployment URL. Got: $Promote"
}
$Promote = $Promote.TrimEnd('/')

Write-Host "Mode: PRODUCTION PROMOTE -> $Promote" -ForegroundColor Yellow
Write-Host "This will move $($liveDomains -join ' and ') onto that deployment." -ForegroundColor Yellow

# Refuse to promote anything that is not a production deployment.
$info = Invoke-Vercel @('inspect', $Promote)
if ($info.ExitCode -ne 0) {
    Write-Error "could not inspect $Promote :`n$($info.Output)"
}
if ($info.Output -notmatch 'target\s+production') {
    Write-Error "SAFETY ABORT: $Promote is not a production deployment. Promotion refused.`n$($info.Output)"
}
if ($info.Output -notmatch 'Ready') {
    Write-Error "SAFETY ABORT: $Promote is not Ready. Promotion refused.`n$($info.Output)"
}
Write-Host 'Verified: target=production, status=Ready' -ForegroundColor Green

# Report which domains are ALREADY on this deployment, one by one.
#
# This used to be a single $alreadyLive boolean, which produced the note
# "already holds the live alias. Nothing to change." while only the APEX domain
# was actually on the deployment - www was still two days stale, serving 404s
# for every component image. A boolean hides a partial state behind a sentence
# that claims there is nothing to do. Ask per domain, and if any domain is NOT
# already there, say which one.
$staleDomains = @()
foreach ($domain in $liveDomains) {
    $current = Invoke-Vercel @('inspect', $domain)
    if ($current.Output -match [regex]::Escape($Promote)) {
        Write-Host "$domain is already on $Promote" -ForegroundColor DarkGray
    } else {
        $staleDomains += $domain
        $found = if ($current.Output -match 'Fetched deployment "([^"]+)"') { $Matches[1] } else { 'unknown' }
        Write-Host "$domain is NOT on this deployment (currently: $found)" -ForegroundColor Yellow
    }
}
if ($staleDomains.Count -eq 0) {
    Write-Host 'Both live domains already serve this deployment.' -ForegroundColor Green
} else {
    Write-Host "Moving $($staleDomains.Count) domain(s) onto $Promote" -ForegroundColor Yellow
}

foreach ($domain in $liveDomains) {
    # NOT $host: that is a READ-ONLY automatic variable in PowerShell, and
    # assigning to it throws, which killed the alias loop mid-run.
    $domainHost = ([uri]$domain).Host
    Write-Host "alias set: $domainHost -> $Promote"
    $a = Invoke-Vercel @('alias', 'set', $Promote, $domainHost)
    if ($a.ExitCode -ne 0) {
        Write-Error "alias set failed for $domainHost :`n$($a.Output)"
    }
}

Write-Host ''
Write-Host 'Verifying live domains...' -ForegroundColor Cyan
$failed = $false
# Check a real component image on every domain, not just the homepage. A domain
# can serve a perfect 200 on every HTML route while every product photo 404s -
# that is exactly the state www.pctechguy.app was in, and the homepage check
# called it healthy. The homepage is no longer accepted as proof on its own.
$verifyImage = if ($imgSample) { "/img/components/$($imgSample.BaseName).jpg" } else { $null }
foreach ($domain in $liveDomains) {
    $code = & curl.exe -s -o NUL -w "%{http_code}" --max-time 60 "$domain/" 2>$null
    Write-Host ("  {0,-30} HTTP {1}" -f $domain, $code)
    if ($code -ne '200') { $failed = $true }
    if ($verifyImage) {
        $imgCode = & curl.exe -s -o NUL -w "%{http_code}" --max-time 60 "$domain$verifyImage" 2>$null
        $imgLabel = if ($imgCode -eq '200') { 'ok' } else { 'MISSING' }
        Write-Host ("  {0,-30} image HTTP {1}  {2}" -f $domain, $imgCode, $imgLabel)
        if ($imgCode -ne '200') { $failed = $true }
    }
}

if ($failed) {
    Write-Error 'one or more live domains failed after promotion (page or component image). Investigate before retrying.'
}

Write-Host ''
Write-Host 'Promoted and verified.' -ForegroundColor Green
Write-Host 'Next: verify nested assets, e.g. /img/placeholders/gpu.svg and /css/filament/filament/app.css' -ForegroundColor Cyan
