# Launch Byparr (headless) for the PC component price/stock pass.
#
# Byparr is the anti-bot proxy the pc-builder price pipeline uses to reach
# uk.pcpartpicker.com, which refuses plain HTTP fetches. It speaks the
# FlareSolverr-compatible API: POST {cmd: "request.get", url, max_timeout}
# to /v1 and it returns rendered HTML after solving the challenge.
#
# Recovered 2026-09-27: the original pc-builder/launch-byparr.ps1 was lost with
# the rest of the untracked JS data pipeline. This is a faithful rebuild.
#
# Usage: powershell -ExecutionPolicy Bypass -File scripts\launch-byparr.ps1
#        powershell -ExecutionPolicy Bypass -File scripts\launch-byparr.ps1 -Headed

param(
    [switch]$Headed,
    [int]$Port = 8191
)

$ErrorActionPreference = 'SilentlyContinue'
$ByparrDir = 'C:\Users\simon\WebstormProjects\Byparr'
$TempDir = 'C:\Users\simon\AppData\Local\Temp\opencode'

if (-not (Test-Path -LiteralPath $ByparrDir)) {
    Write-Error "Byparr not found at $ByparrDir"
    exit 1
}

# Kill any previous Byparr tree: the uv/python entry point plus its Playwright
# driver node. A half-dead instance answers health checks but times out on
# page loads, which looks like a site block when it is really a stale process.
$targets = Get-CimInstance Win32_Process | Where-Object {
    ($_.CommandLine -match 'main\.py' -and ($_.Name -match 'uv\.exe|python\.exe')) -or
    ($_.CommandLine -match 'Byparr.*playwright' -and $_.Name -match 'node\.exe')
}
foreach ($t in $targets) { Stop-Process -Id $t.ProcessId -Force }
Start-Sleep -Seconds 3

$uv = 'C:\Program Files\Python311\Scripts\uv.exe'
if (-not (Test-Path -LiteralPath $uv)) {
    Write-Error "uv.exe not found at $uv"
    exit 1
}

if ($Headed) { $env:HEADLESS = 'false' } else { $env:HEADLESS = 'true' }

$run = Start-Process -FilePath $uv `
    -ArgumentList @('run', 'main.py') `
    -WorkingDirectory $ByparrDir `
    -RedirectStandardOutput "$TempDir\byparr.log" `
    -RedirectStandardError "$TempDir\byparr.err.log" `
    -WindowStyle Hidden -PassThru
$run.Id | Set-Content -LiteralPath "$TempDir\byparr.pid"

Write-Output "byparr launched (pid $($run.Id), headless=$($env:HEADLESS), port $Port)"

# Wait for the port rather than returning before it can serve a request.
$ready = $false
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Seconds 2
    try {
        $null = Invoke-WebRequest -Uri "http://localhost:$Port/v1" -Method GET -TimeoutSec 3 -ErrorAction Stop
        $ready = $true
        break
    } catch {
        # Byparr answers 4xx/5xx on a bare GET /v1 when it is alive, so a
        # connection-level failure is the real "not up yet" signal.
        if ($_.Exception.Message -notmatch 'Unable to connect|refused|actively refused') { $ready = $true; break }
    }
}

if ($ready) {
    Write-Output "byparr is answering on http://localhost:$Port/v1"
} else {
    Write-Output "byparr did NOT come up in time - check $TempDir\byparr.err.log"
    exit 2
}
