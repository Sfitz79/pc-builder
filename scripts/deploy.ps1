<#
.SYNOPSIS
  Re-point pctechguy.app and www.pctechguy.app at the latest Ready production
  deployment after a git push.

.DESCRIPTION
  git pushes auto-deploy to pc-builder-preview.vercel.app, but the pctechguy.app
  aliases are pinned and must be re-pointed manually. Run this after every push:
      powershell -ExecutionPolicy Bypass -File scripts\deploy.ps1

  Exits non-zero on failure. Requires `vercel` CLI authentication.
#>

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location -LiteralPath $repoRoot

$vercel = Join-Path $env:APPDATA 'npm\vercel.cmd'
if (-not (Test-Path -LiteralPath $vercel)) {
    $vercel = (Get-Command vercel.cmd -ErrorAction SilentlyContinue).Source
}
if (-not $vercel) {
    Write-Error 'vercel CLI not found. Install it with: npm i -g vercel'
}

$out = & $vercel ls --prod 2>&1 | Out-String
$latest = @(
    $out -split "`r?`n" |
        Where-Object { $_ -match 'Ready' -and $_ -match 'vercel\.app' } |
        ForEach-Object { if ($_ -match 'https://[a-z0-9][a-z0-9-]*\.vercel\.app') { $Matches[0] } }
)[0]

if (-not $latest) {
    Write-Error 'Could not find a Ready production deployment.'
}

Write-Host "Pointing aliases at $latest"
& $vercel alias set $latest pctechguy.app
if ($LASTEXITCODE -ne 0) { exit 1 }
& $vercel alias set $latest www.pctechguy.app
if ($LASTEXITCODE -ne 0) { exit 1 }

foreach ($domain in @('https://pctechguy.app', 'https://www.pctechguy.app')) {
    $code = curl.exe -s -o NUL -w "%{http_code}" --max-time 60 "$domain/" 2>$null
    Write-Host "$domain -> $code"
}
