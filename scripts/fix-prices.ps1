param(
  [switch]$WhatIf
)

$ErrorActionPreference = "Stop"
$DATA_DIR = [System.IO.Path]::Combine($PSScriptRoot, "..", "src", "data")
$LOG = @()

function Log($msg) {
  $LOG += $msg
  Write-Host $msg
}

function Fix-CSV {
  param($filename, $skipCondition, $fixActions)

  $path = Join-Path $DATA_DIR $filename
  if (!(Test-Path $path)) { Log "  SKIP: $filename not found"; return }

  $content = Get-Content -LiteralPath $path -Raw
  $lines = $content -split "`r?`n" | Where-Object { $_.Trim() -ne "" }
  if ($lines.Count -lt 2) { Log "  SKIP: $filename too short"; return }

  $header = $lines[0]
  $headerParts = $header -split ','
  $priceIdx = [array]::IndexOf($headerParts, 'price')
  $skipIdx = [array]::IndexOf($headerParts, 'skip')
  $nameIdx = 0

  Log "  Processing $filename ($($lines.Count-1) data rows)"

  $changed = 0
  $newLines = @($header)
  $skipped = 0
  $updated = 0

  for ($i = 1; $i -lt $lines.Count; $i++) {
    $line = $lines[$i]

    # Count commas to detect corruption (more commas than header fields)
    $commaCount = 0
    foreach ($c in $line.ToCharArray()) { if ($c -eq ',') { $commaCount++ } }
    $expectedCommas = $headerParts.Count - 1

    # Skip condition
    $shouldSkip = $false
    $shouldUpdate = $null  # hashtable of {line = newline, reason = ""}

    if ($skipCondition) {
      $result = & $skipCondition $line $headerParts $commaCount $expectedCommas
      if ($result -eq "skip") { $shouldSkip = $true }
      elseif ($result -is [hashtable]) { $shouldUpdate = $result }
    }

    if ($shouldSkip) {
      # Comment out the line with skip=1
      $parts = $line -split ','
      if ($skipIdx -ge 0 -and $skipIdx -lt $parts.Count) {
        $parts[$skipIdx] = "1"
        $line = $parts -join ','
      }
      $skipped++
    }
    elseif ($shouldUpdate) {
      $line = $shouldUpdate.line
      $updated++
    }

    $newLines += $line
  }

  $newContent = $newLines -join "`r`n"
  $oldContent = $content.TrimEnd("`r", "`n") + "`r`n"

  if ($skipped -gt 0 -or $updated -gt 0) {
    Log "    → $skipped skipped, $updated updated"
    if (!$WhatIf) {
      [System.IO.File]::WriteAllText($path, $newContent, [System.Text.UTF8Encoding]::new($false))
    }
  } else {
    Log "    → No changes needed"
  }
}

Log "=== PC Builder Price Fix Script ==="
Log ""

# 1. CASE CSV - skip entries at placeholder price £82.83
Log "[1/6] Case CSV - marking £82.83 placeholder entries as skip=1"
Fix-CSV "case.csv" {
  param($line, $headerParts, $commaCount, $expectedCommas)
  $parts = $line -split ','
  $priceIdx = [array]::IndexOf($headerParts, 'price')
  if ($priceIdx -ge 0 -and $priceIdx -lt $parts.Count) {
    $price = $parts[$priceIdx].Trim()
    if ($price -eq "82.83") { return "skip" }
  }
  return $null
}

# 2. RAM CSV - skip corrupt entries (wrong column count due to commas in names)
Log ""
Log "[2/6] RAM CSV - marking corrupt and empty-price entries as skip=1"
Fix-CSV "ram.csv" {
  param($line, $headerParts, $commaCount, $expectedCommas)
  $parts = $line -split ','
  $priceIdx = [array]::IndexOf($headerParts, 'price')

  # Corrupt: wrong number of commas
  if ($commaCount -ne $expectedCommas) { return "skip" }

  # Empty price
  if ($priceIdx -ge 0 -and $priceIdx -lt $parts.Count) {
    $price = $parts[$priceIdx].Trim()
    if ($price -eq "") { return "skip" }
  }

  return $null
}

# 3. GPU CSV - skip entries with empty price
Log ""
Log "[3/6] GPU CSV - marking empty-price entries as skip=1"
Fix-CSV "gpu.csv" {
  param($line, $headerParts, $commaCount, $expectedCommas)
  $parts = $line -split ','
  $priceIdx = [array]::IndexOf($headerParts, 'price')
  if ($priceIdx -ge 0 -and $priceIdx -lt $parts.Count) {
    $price = $parts[$priceIdx].Trim()
    if ($price -eq "") { return "skip" }
  }
  return $null
}

# 4. CPU CSV - fix AMD Ryzen 9 9950X3D2 duplicate name
Log ""
Log "[4/6] CPU CSV - fixing anomalous entries"
Fix-CSV "cpu.csv" {
  param($line, $headerParts, $commaCount, $expectedCommas)
  $parts = $line -split ','
  $priceIdx = [array]::IndexOf($headerParts, 'price')
  if ($priceIdx -ge 0 -and $priceIdx -lt $parts.Count) {
    $price = $parts[$priceIdx].Trim()
    if ($price -eq "") { return "skip" }
    # Check for unreasonably low/high prices
    if ($price -match '^\d+\.?\d*$') {
      $p = [double]$price
      if ($p -lt 10) { return "skip" }
    }
  }
  return $null
}

# 5. All other CSVs - skip entries with empty or zero price
Log ""
Log "[5/6] All other CSVs - marking empty/zero-price entries as skip=1"
$csvs = @(
  "cooler.csv", "motherboard.csv", "power-supply.csv", "storage.csv",
  "case-fan.csv", "keyboard.csv", "mouse.csv", "monitor.csv",
  "headphones.csv", "speakers.csv", "webcam.csv", "wireless-network-card.csv",
  "wired-network-card.csv", "sound-card.csv", "optical-drive.csv",
  "ups.csv", "fan-controller.csv", "thermal-paste.csv",
  "case-accessory.csv", "external-hard-drive.csv", "os.csv"
)
foreach ($csv in $csvs) {
  Fix-CSV $csv {
    param($line, $headerParts, $commaCount, $expectedCommas)
    $parts = $line -split ','
    $priceIdx = [array]::IndexOf($headerParts, 'price')

    # Wrong column count
    if ($commaCount -ne $expectedCommas) { return "skip" }

    if ($priceIdx -ge 0 -and $priceIdx -lt $parts.Count) {
      $price = $parts[$priceIdx].Trim()
      if ($price -eq "") { return "skip" }
    }
    return $null
  }
}

# 6. OS CSV - fix Windows 11 Pro price
Log ""
Log "[6/6] OS CSV - fixing Windows 11 Pro Retail price"
# The store defaults to Windows 11 Pro Retail at £189.99, but CSV shows £45
# Updating CSV to match the store default
Fix-CSV "os.csv" {
  param($line, $headerParts, $commaCount, $expectedCommas)
  $parts = $line -split ','
  $nameIdx = 0
  $priceIdx = [array]::IndexOf($headerParts, 'price')

  if ($priceIdx -ge 0 -and $parts.Count -gt $nameIdx) {
    $name = $parts[$nameIdx].Trim()
    if ($name -eq "Microsoft Windows 11 Pro Retail - Download 64-bit") {
      $parts[$priceIdx] = "189.99"
      return @{ line = $parts -join ','; reason = "Updated Win 11 Pro price to £189.99" }
    }
  }
  return $null
}

Log ""
Log "=== Done ==="
if ($WhatIf) {
  Log "NOTE: Run without -WhatIf to apply changes"
}
