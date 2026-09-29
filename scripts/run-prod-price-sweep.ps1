<#
    Run the FULL PCPP price sweep against PRODUCTION Neon, in batches of 200.

    Usage:
        powershell -ExecutionPolicy Bypass -File scripts\run-prod-price-sweep.ps1
            -BatchFile database\scraped\prod-batches\batch-001.json
            [-DryRun] [-SkipMerchant] [-CooldownSeconds 90] [-LogDir database\scraped\prod-batches\logs]
    [-ForceRefresh]

    WHY THIS EXISTS
    The local .env points at SQLite. Running components:refresh-prices plainly
    would stamp the LOCAL file, which is NOT production coverage. This script
    loads .env.production.neon into the process environment first (same
    mechanism run-prod-test.ps1 uses, values never echoed) so every write lands
    on Neon - the only DB whose coverage count matters.

    SCRAPER_API_KEY is NOT stored in .env.production.neon (only DB_* keys are).
    It is exported from the local .env so the ScraperAPI lane is available
    during the sweep without persisting the key in the production env file.

    Default is merchant-CONFIRMED writes (fail-closed). --SkipMerchant exists
    for measurement passes only and prints a loud warning. --DryRun writes
    nothing anywhere but still spends ScraperAPI credits, so it is for
    measuring the real hit rate before a paid write pass.
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $BatchFile,

    [switch] $DryRun,
    [switch] $SkipMerchant,
    [int] $CooldownSeconds = 60,
    [string] $LogDir = 'database\scraped\prod-batches\logs',
    [switch] $ForceRefresh
)

$ErrorActionPreference = 'Stop'

# Scheduled tasks run with a minimal PATH, so `php` may not resolve. Put the
# WinGet php directory on PATH explicitly (relative cmd /c then finds it).
$phpDir = 'C:\Users\simon\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe'
if (-not (Split-Path -Parent (Get-Command php -ErrorAction SilentlyContinue).Source -ErrorAction SilentlyContinue) -or -not (Test-Path (Join-Path $phpDir 'php.exe'))) {
    # fall through to whatever php resolves; the PATH guard below handles the rest
}
if (Test-Path (Join-Path $phpDir 'php.exe')) {
    $env:PATH = "$phpDir;$env:PATH"
}

# php artisan's progress bar writes to stderr; PowerShell's stderr capture
# raises NativeCommandError records for those lines, so the harness must drop
# to Continue around native calls (same pattern as deploy.ps1) or the first
# progress tick terminates the pipeline and kills php mid-run.
$savedEAP = $ErrorActionPreference

$root = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $root '.env.production.neon'

if (-not (Test-Path -LiteralPath $envFile)) {
    Write-Error "Production env file not found: $envFile"
    exit 2
}

if (-not (Test-Path -LiteralPath (Join-Path $root $BatchFile))) {
    Write-Error "Batch file not found: $BatchFile"
    exit 2
}

if ($SkipMerchant) {
    Write-Host 'WARNING: --SkipMerchant is a MEASUREMENT-ONLY lane. Do NOT use it for a production write pass.' -ForegroundColor Yellow
}

# Load .env.production.neon into this process only. Values are never printed.
$loaded = 0
foreach ($line in Get-Content -LiteralPath $envFile) {
    $trimmed = $line.Trim()
    if ($trimmed -eq '' -or $trimmed.StartsWith('#')) { continue }
    $eq = $trimmed.IndexOf('=')
    if ($eq -lt 1) { continue }
    $key = $trimmed.Substring(0, $eq).Trim()
    $value = $trimmed.Substring($eq + 1).Trim().Trim('"')
    Set-Item -Path "env:$key" -Value $value
    $loaded++
}

# Export SCRAPER_API_KEY from the LOCAL .env so the ScraperAPI lane works
# without persisting the key into .env.production.neon.
$localEnv = Join-Path $root '.env'
foreach ($line in Get-Content -LiteralPath $localEnv) {
    if ($line -match '^SCRAPER_API_KEY=') {
        $value = $line.Substring($line.IndexOf('=') + 1).Trim().Trim('"')
        if ($value -ne '') {
            Set-Item -Path 'env:SCRAPER_API_KEY' -Value $value
            Write-Host 'SCRAPER_API_KEY exported from local .env (value hidden).'
        }
    }
}

# The DIRECT Neon host is used, not the pooled one (see run-prod-test.ps1).
$env:DB_CONNECTION = 'pgsql'

Write-Host "Loaded $loaded production env keys + pgsql driver (values hidden)."

New-Item -ItemType Directory -Force -Path (Join-Path $root $LogDir) | Out-Null
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$batchName = [System.IO.Path]::GetFileNameWithoutExtension($BatchFile)
$logFile = Join-Path $root (Join-Path $LogDir "$batchName-$stamp.log")

$argsList = @('artisan', 'components:refresh-prices', "--ids-file=$BatchFile", '--force')
if ($DryRun) { $argsList += '--dry-run' }
if ($SkipMerchant) { $argsList += '--skip-merchant-check' }
$argsList = $null # cmd /c builds the command line instead; kept null to prevent accidental reuse

$flagStr = ''
# --force is NOT the default: without it, rows already stamped fresh (within
# FRESH_DAYS) are skipped by the command's freshness filter, so an interrupted
# batch resumes cheaply instead of re-burning ScraperAPI credits on rows that
# already have today's evidence. Pass -ForceRefresh to re-verify everything.
if ($ForceRefresh) { $flagStr += ' --force' }
if ($DryRun) { $flagStr += ' --dry-run' }
if ($SkipMerchant) { $flagStr += ' --skip-merchant-check' }

Push-Location $root
try {
    Write-Host "Running: components:refresh-prices --ids-file=$BatchFile$flagStr -> $logFile"
    # stdout+stderr go straight to the log file via cmd's redirection so the
    # Symfony progress bar (stderr) never becomes a PowerShell NativeCommandError
    # and never kills php mid-run.
    cmd /c "php artisan components:refresh-prices --ids-file=`"$BatchFile`"$flagStr > `"$logFile`" 2>&1"
    $code = $LASTEXITCODE
}
finally {
    Pop-Location
}

if ($code -ne 0) {
    Write-Error "Batch failed (exit $code). Log: $logFile"
}

if ($CooldownSeconds -gt 0) {
    Write-Host "Cooling down $CooldownSeconds s before next batch..."
    Start-Sleep -Seconds $CooldownSeconds
}

Write-Host "Log: $logFile"
exit $code