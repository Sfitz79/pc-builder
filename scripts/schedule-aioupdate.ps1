param(
  [switch]$Remove,
  [switch]$Status
)

$rootDir = Split-Path -Parent $PSScriptRoot
$nodeExe = (Get-Command node).Source

$imgupScript = Join-Path $PSScriptRoot "imgup.js"
$priceupdScript = Join-Path $PSScriptRoot "priceupd.js"

$imgupTask = "PCTGPcBuilder-ImgUpdate"
$priceupdTask = "PCTGPcBuilder-PriceUpdate"
$oldTask = "PCTGPcBuilder-AIOUpdate"

if ($Remove) {
  foreach ($t in @($imgupTask, $priceupdTask, $oldTask)) {
    try { Unregister-ScheduledTask -TaskName $t -Confirm:$false; Write-Host "[Sched] Removed '$t'" -ForegroundColor Yellow } catch {}
  }
  return
}

if ($Status) {
  foreach ($t in @($imgupTask, $priceupdTask, $oldTask)) {
    try {
      $task = Get-ScheduledTask -TaskName $t -ErrorAction Stop
      Write-Host "[Sched] '$t': $($task.State)" -ForegroundColor Cyan
    } catch {
      Write-Host "[Sched] '$t': not found" -ForegroundColor DarkGray
    }
  }
  return
}

# Remove old task if exists
try { Unregister-ScheduledTask -TaskName $oldTask -Confirm:$false; Write-Host "[Sched] Removed old '$oldTask'" -ForegroundColor Yellow } catch {}

function New-Task($Name, $Script, $Trigger, $Duration) {
  $action = New-ScheduledTaskAction -Execute $nodeExe -Argument "`"$Script`" --no-dashboard" -WorkingDirectory $rootDir
  $currentUser = "$env:USERDOMAIN\$env:USERNAME"
  $principal = New-ScheduledTaskPrincipal -UserId $currentUser -LogonType Interactive -RunLevel Limited
  $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit $Duration -Compatibility Win8
  Register-ScheduledTask -TaskName $Name -Action $action -Trigger $Trigger -Principal $principal -Settings $settings -Force
}

Write-Host "[Sched] Creating tasks..." -ForegroundColor Cyan

# Weekly Friday 23:00 — imgup (image/thumbnail update)
$weeklyTrigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek Friday -At 23:00
New-Task -Name $imgupTask -Script $imgupScript -Trigger $weeklyTrigger -Duration (New-TimeSpan -Hours 21)
Write-Host "  ✓ $imgupTask — Weekly Fri 23:00 → Sat 20:00" -ForegroundColor Green

# Daily 08:00 — priceupd (CSV data + pricing)
$dailyTrigger = New-ScheduledTaskTrigger -Daily -At "08:00"
New-Task -Name $priceupdTask -Script $priceupdScript -Trigger $dailyTrigger -Duration (New-TimeSpan -Hours 2)
Write-Host "  ✓ $priceupdTask — Daily at 08:00" -ForegroundColor Green

Write-Host ""
Write-Host "  Status:  .\scripts\schedule-aioupdate.ps1 -Status" -ForegroundColor White
Write-Host "  Remove:  .\scripts\schedule-aioupdate.ps1 -Remove" -ForegroundColor White
