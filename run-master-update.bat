@echo off
title Master Update — Progress Dashboard
cd /d "%~dp0"
echo ============================================
echo  Master Update Fast — Genuine Images Mode
echo ============================================
echo.
echo  Pipeline:
echo    1. Filter discontinued items
echo    2. Scrape genuine images from retailers
echo    3. Fallback to web search if no image found
echo    4. Download to public/thumbnails/
echo.
echo  Opening progress window...
echo.
powershell -ExecutionPolicy Bypass -File "%~dp0master-update-dashboard.ps1"
if %ERRORLEVEL% NEQ 0 (
  echo.
  echo PowerShell failed — falling back to direct run...
  npm run master-update:direct
  pause
)
