<#
    Run the COMPLETE PCPP price sweep against PRODUCTION Neon: batches 001-014,
    one after another, with a cooldown between batches. Designed to be started
    as a DETACHED SCHEDULED TASK so tool interruptions cannot kill it.

    RESUME-AWARE on BOTH levels:
    1. The runner defaults to NO --force, so rows already stamped fresh (within
       FRESH_DAYS) are skipped by the command. An interrupted batch therefore
       resumes cheaply instead of re-burning ScraperAPI credits.
    2. This driver records a per-batch marker (sweep-state.json) ONLY after the
       batch runner exits 0. If the driver itself is killed and restarted, any
       batch whose marker exists is skipped entirely. A batch that was in
       flight with NO marker is simply re-entered; the runner skips its
       already-stamped rows.

    Usage:
        powershell -ExecutionPolicy Bypass -File scripts\run-full-prod-sweep.ps1
            [-CooldownSeconds 60] [-SkipMerchant] [-ForceRefresh]
#>
[CmdletBinding()]
param(
    [int] $CooldownSeconds = 60,
    [switch] $SkipMerchant,
    [switch] $ForceRefresh
)

$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$batchDir = Join-Path $root 'database\scraped\prod-batches'
$masterLog = Join-Path $root 'database\scraped\prod-batches\master-sweep.log'
$stateFile = Join-Path $root 'database\scraped\prod-batches\sweep-state.json'

$batches = Get-ChildItem -LiteralPath $batchDir -Filter 'batch-*.json' | Sort-Object Name

if ($batches.Count -eq 0) {
    Write-Error 'No batch files found.'
    exit 2
}

# Load resume state (completed batch file names, no extension).
$state = @{ completed = @() }
if (Test-Path -LiteralPath $stateFile) {
    try {
        $loaded = Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json
        if ($loaded.completed) { $state.completed = @($loaded.completed) }
    } catch {
        Write-Host "WARN: sweep-state.json unreadable ($($_.Exception.Message)); starting fresh." | Tee-Object -FilePath $masterLog -Append
    }
}

Write-Host "FULL SWEEP START $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss') - $($batches.Count) batches, cooldown ${CooldownSeconds}s, force=$ForceRefresh" | Tee-Object -FilePath $masterLog -Append
Write-Host "SkipMerchant=$SkipMerchant (must be FALSE for production writes)" | Tee-Object -FilePath $masterLog -Append
Write-Host "Already-completed: $($state.completed.Count) batch(es)" | Tee-Object -FilePath $masterLog -Append

foreach ($batch in $batches) {
    $name = [System.IO.Path]::GetFileNameWithoutExtension($batch.Name)

    if ($state.completed -contains $name) {
        Write-Host "SKIP $name (completed in a prior run) $(Get-Date -Format 'HH:mm:ss')" | Tee-Object -FilePath $masterLog -Append
        continue
    }

    Write-Host "BATCH $(Get-Date -Format 'HH:mm:ss') $name START" | Tee-Object -FilePath $masterLog -Append

    $args = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File',
        (Join-Path $root 'scripts\run-prod-price-sweep.ps1'),
        '-BatchFile', "database\scraped\prod-batches\$($batch.Name)",
        "-CooldownSeconds", "$CooldownSeconds"
    )
    if ($SkipMerchant) { $args += '-SkipMerchant' }
    if ($ForceRefresh) { $args += '-ForceRefresh' }

    & powershell.exe @args 2>&1 | Out-File -Append -FilePath $masterLog
    $exit = $LASTEXITCODE

    if ($exit -eq 0) {
        $state.completed += $name
        $state.completed = @($state.completed | Sort-Object -Unique)
        # Persist marker so a future driver restart skips this batch.
        try {
            $state | ConvertTo-Json | Set-Content -LiteralPath $stateFile -Encoding UTF8
        } catch {
            Write-Host "WARN: could not persist sweep-state.json ($($_.Exception.Message))" | Tee-Object -FilePath $masterLog -Append
        }
        Write-Host "BATCH $name DONE (exit 0, marked complete) $(Get-Date -Format 'HH:mm:ss')" | Tee-Object -FilePath $masterLog -Append
    } else {
        Write-Host "BATCH $name FAILED (exit $exit) - NOT marked complete; will retry on next run $(Get-Date -Format 'HH:mm:ss')" | Tee-Object -FilePath $masterLog -Append
    }
}

Write-Host "FULL SWEEP END $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" | Tee-Object -FilePath $masterLog -Append