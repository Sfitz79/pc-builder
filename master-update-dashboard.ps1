# master-update-dashboard.ps1
# Windows-native GUI dashboard showing live progress for master-update-fast
# Opens as a separate window with real progress bars

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

$script:baseDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$script:progressFile = Join-Path $baseDir "master-update-progress.json"
$script:logFile = Join-Path $baseDir "master-update-fast.log"
$script:updateProcess = $null

# ---- GUI Setup ----
$form = New-Object System.Windows.Forms.Form
$form.Text = "Master Update — Live Progress"
$form.Size = New-Object System.Drawing.Size(640, 520)
$form.StartPosition = "CenterScreen"
$form.BackColor = "#0d1117"
$form.ForeColor = "#c9d1d9"
$form.Font = New-Object System.Drawing.Font("Segoe UI", 10)
$form.Icon = $null  # no icon needed
$form.TopMost = $false

# ---- Status header ----
$lblStatus = New-Object System.Windows.Forms.Label
$lblStatus.Location = New-Object System.Drawing.Point(16, 12)
$lblStatus.Size = New-Object System.Drawing.Size(600, 22)
$lblStatus.Text = "Initialising..."
$lblStatus.ForeColor = "#58a6ff"
$form.Controls.Add($lblStatus)

# ---- Overall progress bar ----
$lblOverall = New-Object System.Windows.Forms.Label
$lblOverall.Location = New-Object System.Drawing.Point(16, 44)
$lblOverall.Size = New-Object System.Drawing.Size(600, 18)
$lblOverall.Text = "Overall Progress"
$lblOverall.ForeColor = "#8b949e"
$form.Controls.Add($lblOverall)

$progressOverall = New-Object System.Windows.Forms.ProgressBar
$progressOverall.Location = New-Object System.Drawing.Point(16, 64)
$progressOverall.Size = New-Object System.Drawing.Size(600, 24)
$progressOverall.Minimum = 0
$progressOverall.Maximum = 100
$progressOverall.Value = 0
$progressOverall.Style = "Continuous"
$form.Controls.Add($progressOverall)

$lblOverallPct = New-Object System.Windows.Forms.Label
$lblOverallPct.Location = New-Object System.Drawing.Point(16, 90)
$lblOverallPct.Size = New-Object System.Drawing.Size(600, 18)
$lblOverallPct.Text = "0 / 0 (0%)"
$lblOverallPct.ForeColor = "#8b949e"
$form.Controls.Add($lblOverallPct)

# ---- Pass + Remaining ----
$lblPassRem = New-Object System.Windows.Forms.Label
$lblPassRem.Location = New-Object System.Drawing.Point(16, 108)
$lblPassRem.Size = New-Object System.Drawing.Size(600, 18)
$lblPassRem.Text = "Pass: 1  |  Remaining: 0 items"
$lblPassRem.ForeColor = "#d29922"
$form.Controls.Add($lblPassRem)

# ---- Stats panel ----
$lblStats = New-Object System.Windows.Forms.Label
$lblStats.Location = New-Object System.Drawing.Point(16, 128)
$lblStats.Size = New-Object System.Drawing.Size(600, 18)
$lblStats.Text = "Images — Found: 0  |  Skipped: 0  |  Failed: 0  |  Removed: 0"
$lblStats.ForeColor = "#3fb950"
$form.Controls.Add($lblStats)

# ---- Current action ----
$lblAction = New-Object System.Windows.Forms.Label
$lblAction.Location = New-Object System.Drawing.Point(16, 150)
$lblAction.Size = New-Object System.Drawing.Size(600, 18)
$lblAction.Text = "Waiting..."
$lblAction.ForeColor = "#d29922"
$form.Controls.Add($lblAction)

# ---- Category progress list (listbox) ----
$lblCatHeader = New-Object System.Windows.Forms.Label
$lblCatHeader.Location = New-Object System.Drawing.Point(16, 178)
$lblCatHeader.Size = New-Object System.Drawing.Size(600, 18)
$lblCatHeader.Text = "Categories"
$lblCatHeader.ForeColor = "#8b949e"
$form.Controls.Add($lblCatHeader)

$listCategories = New-Object System.Windows.Forms.ListBox
$listCategories.Location = New-Object System.Drawing.Point(16, 192)
$listCategories.Size = New-Object System.Drawing.Size(600, 230)
$listCategories.BackColor = "#161b22"
$listCategories.ForeColor = "#c9d1d9"
$listCategories.BorderStyle = "FixedSingle"
$listCategories.Font = New-Object System.Drawing.Font("Consolas", 9)
$form.Controls.Add($listCategories)

# ---- Bottom row: log + close ----
$txtLog = New-Object System.Windows.Forms.TextBox
$txtLog.Location = New-Object System.Drawing.Point(16, 450)
$txtLog.Size = New-Object System.Drawing.Size(508, 22)
$txtLog.BackColor = "#0d1117"
$txtLog.ForeColor = "#8b949e"
$txtLog.BorderStyle = "FixedSingle"
$txtLog.ReadOnly = $true
$form.Controls.Add($txtLog)

$btnClose = New-Object System.Windows.Forms.Button
$btnClose.Location = New-Object System.Drawing.Point(540, 448)
$btnClose.Size = New-Object System.Drawing.Size(76, 26)
$btnClose.Text = "Close"
$btnClose.BackColor = "#21262d"
$btnClose.ForeColor = "#c9d1d9"
$btnClose.FlatStyle = "Flat"
$btnClose.FlatAppearance.BorderColor = "#30363d"
$btnClose.Add_Click({
  $timer.Stop()
  $form.Close()
  if ($script:updateProcess -and !$script:updateProcess.HasExited) {
    $script:updateProcess.Kill()
  }
})
$form.Controls.Add($btnClose)

# ---- Timer for polling ----
$timer = New-Object System.Windows.Forms.Timer
$timer.Interval = 1500
$timer.Add_Tick({
  $form.Text = "Master Update — Live Progress [polling...]"
  if (Test-Path $script:progressFile) {
    try {
      $data = Get-Content $script:progressFile -Raw | ConvertFrom-Json
      $total = [Math]::Max(1, $data.overallTotal)
      $pct = [Math]::Min(100, [Math]::Round(($data.overallProcessed / $total) * 100))
      $progressOverall.Value = $pct
      $lblOverallPct.Text = "$($data.overallProcessed) / $total ($pct%)"
      $lblStatus.Text = "Status: $($data.status)  |  Elapsed: $($data.elapsedFormatted)"
      $lblPassRem.Text = "Pass: $($data.currentPass)  |  Remaining: $($data.totalRemaining) items"
      $lblStats.Text = "Images — Found: $($data.imagesFound)  |  Skipped: $($data.imagesSkipped)  |  Failed: $($data.imagesFailed)  |  Removed: $($data.filteredRemoved)"
      if ($data.currentCategory) {
        $lblAction.Text = "> $($data.currentCategory): $($data.currentItem) / $($data.totalItems)"
      }
      # Build category list
      $catLines = @()
      foreach ($key in ($data.categories.PSObject.Properties.Name | Sort-Object)) {
        $c = $data.categories.$key
        $cpct = if ($c.total -gt 0) { [Math]::Round(($c.processed / $c.total) * 100) } else { 0 }
        $statusChar = if ($c.done) { "[DONE]" } elseif ($c.active) { "[>>]" } else { "[..]" }
        $rem = if ($c.remaining -and $c.remaining -gt 0) { " rem:$($c.remaining)" } else { "" }
        $catLines += "$statusChar $key".PadRight(40) + ("$($c.processed)/$($c.total)").PadLeft(10) + (" $cpct%").PadLeft(6) + (" img:$($c.images)").PadLeft(10) + $rem.PadLeft(10)
      }
      if ($catLines.Count -gt 0) {
        $listCategories.Items.Clear()
        foreach ($line in $catLines) { $listCategories.Items.Add($line) }
      } else {
        $listCategories.Items.Clear()
        $listCategories.Items.Add("Waiting for data...")
      }
      if ($data.status -eq "complete") {
        $lblStatus.Text = "COMPLETED ✓  |  Elapsed: $($data.elapsedFormatted)  |  Close when done"
        $lblStatus.ForeColor = "#3fb950"
        $txtLog.Text = "Process completed successfully. Check master-update-summary.txt for details."
      } elseif ($data.status -eq "error") {
        $lblStatus.Text = "ERROR: $($data.error)"
        $lblStatus.ForeColor = "#f85149"
      }
      $form.Text = "Master Update — $pct% [$($data.status)]"
    } catch {
      $lblStatus.Text = "Reading progress data..."
    }
  } else {
    $listCategories.Items.Clear()
    $listCategories.Items.Add("Waiting for master-update-progress.json...")
  }
})

# ---- Start the update process ----
function Start-UpdateProcess {
  $scriptPath = Join-Path $script:baseDir "master-update-fast.js"
  if (!(Test-Path $scriptPath)) {
    $lblStatus.Text = "ERROR: master-update-fast.js not found!"
    return
  }
  try {
    $script:updateProcess = Start-Process -FilePath "node" -ArgumentList $scriptPath -WorkingDirectory $script:baseDir -NoNewWindow -PassThru -RedirectStandardOutput (Join-Path $env:TEMP "mu-stdout.txt") -RedirectStandardError (Join-Path $env:TEMP "mu-stderr.txt")
    $lblStatus.Text = "Started master-update-fast.js (PID: $($script:updateProcess.Id))"
  } catch {
    $lblStatus.Text = "ERROR starting process: $_"
  }
}

# ---- Cleanup on close ----
$form.Add_FormClosed({
  $timer.Stop()
  if ($script:updateProcess -and !$script:updateProcess.HasExited) {
    $script:updateProcess.Kill()
  }
})

# ---- Go! ----
Start-UpdateProcess
$timer.Start()
$form.ShowDialog()
