param(
  [switch]$Remove,
  [switch]$Status
)

# Unified master-update.js scheduler
#
#   Task A  PCTGPcBuilder-Update4d    every 4 days 03:00 — prices + images (full pipeline)
#   Task B  PCTGPcBuilder-NewProducts every 7 days 04:00 — merge scraped JSON -> CSVs + images
#
# Both run headless/minimised in the background and write master-update-progress.json.
# View live status any time with:  .\status-dashboard.bat   (node master-update.js --dashboard-only)

$rootDir = Split-Path -Parent $PSScriptRoot
$nodeExe = (Get-Command node).Source
$masterScript = Join-Path $rootDir "master-update.js"

$update4dTask = "PCTGPcBuilder-Update4d"
$newProdTask = "PCTGPcBuilder-NewProducts"

# Old tasks (replaced by unified master-update.js)
$legacyTasks = @(
  "PCTGPcBuilder-ImgUpdate",
  "PCTGPcBuilder-PriceUpdate",
  "PCTGPcBuilder-AIOUpdate"
)

if ($Remove) {
  foreach ($t in @($update4dTask, $newProdTask) + $legacyTasks) {
    try { Unregister-ScheduledTask -TaskName $t -Confirm:$false; Write-Host "[Sched] Removed '$t'" -ForegroundColor Yellow } catch {}
  }
  return
}

if ($Status) {
  foreach ($t in @($update4dTask, $newProdTask) + $legacyTasks) {
    try {
      $task = Get-ScheduledTask -TaskName $t -ErrorAction Stop
      Write-Host "[Sched] '$t': $($task.State)" -ForegroundColor Cyan
    } catch {
      Write-Host "[Sched] '$t': not found" -ForegroundColor DarkGray
    }
  }
  return
}

# Remove legacy tasks
foreach ($t in $legacyTasks) {
  try { Unregister-ScheduledTask -TaskName $t -Confirm:$false; Write-Host "[Sched] Removed legacy '$t'" -ForegroundColor Yellow } catch {}
}

function New-Task($Name, $ArgumentList, $Trigger, $Duration) {
  $action = New-ScheduledTaskAction -Execute $nodeExe -Argument $ArgumentList -WorkingDirectory $rootDir
  $currentUser = "$env:USERDOMAIN\$env:USERNAME"
  $principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
  $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit $Duration -Compatibility Win8 -MultipleInstances IgnoreNew
  Register-ScheduledTask -TaskName $Name -Action $action -Trigger $Trigger -Principal $principal -Settings $settings -Force
}

Write-Host "[Sched] Creating tasks..." -ForegroundColor Cyan

# Task A — every 4 days 03:00 — prices + images (full pipeline, keeps zero-availability items)
$arg4d = "`"$masterScript`" --no-dashboard"
$trigger4d = New-ScheduledTaskTrigger -Daily -DaysInterval 4 -At "03:00"
New-Task -Name $update4dTask -ArgumentList $arg4d -Trigger $trigger4d -Duration (New-TimeSpan -Hours 12)
Write-Host "  ✓ $update4dTask — every 4 days 03:00 (prices + images)" -ForegroundColor Green

# Task B — every 7 days 04:00 — check for new products (merge scraped data + images)
$arg7d = "`"$masterScript`" --new-products --no-dashboard"
$trigger7d = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Monday -At "04:00"
New-Task -Name $newProdTask -ArgumentList $arg7d -Trigger $trigger7d -Duration (New-TimeSpan -Hours 12)
Write-Host "  ✓ $newProdTask — weekly Mon 04:00 (new products check)" -ForegroundColor Green

Write-Host ""
Write-Host "  Both tasks run headless in the background; progress is written to" -ForegroundColor White
Write-Host "  master-update-progress.json (view via .\status-dashboard.bat)." -ForegroundColor White
Write-Host ""
Write-Host "  Status:  .\scripts\schedule-aioupdate.ps1 -Status" -ForegroundColor White
Write-Host "  Remove:  .\scripts\schedule-aioupdate.ps1 -Remove" -ForegroundColor White
