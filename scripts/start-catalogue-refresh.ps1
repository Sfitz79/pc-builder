# Full catalogue refresh - price, stock, availability and image in one pass.
#
# Why detached: a full pass is ~2,700 Byparr fetches. At a polite delay that is
# hours of wall clock, far longer than any single session, so it runs as a
# background process and we inspect its log as it goes.
#
# Why resumable: components:refresh-catalogue skips rows whose price_checked_at
# is inside PriceIntegrityService::FRESH_DAYS. So if this dies (network, Byparr
# restart) you just run it again and it picks up where it left off. That also
# means it is safe to re-run daily; only stale rows get re-fetched.

$ErrorActionPreference = 'Stop'
$root = 'C:\Users\simon\WebStormProjects\pc-builder'
$log = Join-Path $root 'storage\logs\catalogue-refresh.log'

# Byparr must be up or every component fails. Fail loudly rather than burning
# 2,700 retries against a dead proxy.
try {
    $r = Invoke-WebRequest -Uri 'http://127.0.0.1:8191' -TimeoutSec 8 -UseBasicParsing
    Write-Output "Byparr 8191 responding (HTTP $($r.StatusCode))"
} catch {
    Write-Output "Byparr 8191 is DOWN: $($_.Exception.Message)"
    Write-Output 'Start it with: scripts\launch-byparr.ps1'
    exit 1
}

$stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
Add-Content -LiteralPath $log -Value "`n===== catalogue refresh started $stamp ====="

# Ordered by price descending inside the command, so GPU/RAM/storage get
# fetched first: they are both the highest-value rows and the categories the
# 2026 shortage hit hardest. Delay is polite to PCPP's Cloudflare edge.
# `$args` is a READ-ONLY automatic variable in PowerShell. Assigning to it is
# the same class of bug as the `$host` collision that aborted deploy.ps1 on
# 2026-09-28 - it fails at runtime, long after it looks fine on screen.
$refreshArgs = @(
    'artisan', 'components:refresh-catalogue',
    '--delay=1.5', '--attempts=3'
)

$p = Start-Process -FilePath 'php' `
    -ArgumentList $refreshArgs `
    -WorkingDirectory $root `
    -RedirectStandardOutput $log `
    -RedirectStandardError "$log.err" `
    -WindowStyle Hidden `
    -PassThru

Write-Output "launched pid $($p.Id) -> $log"
