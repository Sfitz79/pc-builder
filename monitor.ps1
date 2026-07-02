$statusFile = "C:\Users\simon\WebstormProjects\pc-builder\master-update-status.txt"
$logFile = "C:\Users\simon\WebstormProjects\pc-builder\master-update.log"
$thumbDir = "C:\Users\simon\WebstormProjects\pc-builder\public\thumbnails"
$progressFile = "C:\Users\simon\WebstormProjects\pc-builder\master-update-progress.json"

Clear-Host
Write-Host "=== MASTER UPDATE LIVE MONITOR ===" -ForegroundColor Cyan
Write-Host "PID: 44120 | Opened: $(Get-Date -Format 'HH:mm:ss')" -ForegroundColor Yellow
Write-Host ""

while ($true) {
    if (Test-Path $statusFile) {
        Clear-Host
        Write-Host "=== MASTER UPDATE LIVE MONITOR ===" -ForegroundColor Cyan
        Write-Host "PID: 44120 | $(Get-Date -Format 'HH:mm:ss')" -ForegroundColor Yellow
        Write-Host ""

        $status = Get-Content $statusFile -Raw -ErrorAction SilentlyContinue
        if ($status) {
            $lines = $status -split "`n"
            foreach ($line in $lines) {
                if ($line.Trim() -ne "") {
                    Write-Host $line -ForegroundColor Green
                }
            }
        }

        Write-Host ""
        $thumbCount = (Get-ChildItem $thumbDir -ErrorAction SilentlyContinue).Count
        Write-Host "Thumbnails downloaded: $thumbCount" -ForegroundColor Magenta

        if (Test-Path $progressFile) {
            try {
                $prog = Get-Content $progressFile -Raw | ConvertFrom-Json
                $totalPrices = 0
                $totalImages = 0
                foreach ($cat in $prog.categories.PSObject.Properties) {
                    $totalPrices += $cat.Value.pricesFound
                    $totalImages += $cat.Value.imagesFound
                }
                Write-Host "Total prices found: $totalPrices" -ForegroundColor Cyan
                Write-Host "Total images found: $totalImages" -ForegroundColor Cyan
                Write-Host "Completed categories: $($prog.completedCategories.Count)" -ForegroundColor Cyan
            } catch {}
        }

        Write-Host ""
        Write-Host "=== Recent Log ===" -ForegroundColor Cyan
        $lastLog = Get-Content $logFile -Tail 3 -ErrorAction SilentlyContinue
        foreach ($line in $lastLog) {
            Write-Host $line -ForegroundColor Gray
        }
    } else {
        Clear-Host
        Write-Host "=== MASTER UPDATE LIVE MONITOR ===" -ForegroundColor Cyan
        Write-Host "Waiting for status file..." -ForegroundColor Yellow
    }

    Start-Sleep -Seconds 3
}
