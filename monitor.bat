@echo off
cls
echo === MASTER UPDATE MONITOR ===
echo PID: 44120
echo.

:loop
cls
echo === MASTER UPDATE MONITOR ===
echo PID: 44120 - %DATE% %TIME%
echo.

if exist "C:\Users\simon\WebstormProjects\pc-builder\master-update-status.txt" (
    type "C:\Users\simon\WebstormProjects\pc-builder\master-update-status.txt"
    echo.
    setlocal enabledelayedexpansion
    set count=0
    for %%f in ("C:\Users\simon\WebstormProjects\pc-builder\public\thumbnails\*") do set /a count+=1
    echo Thumbnails: !count!
    endlocal
    echo.
    echo === Recent Log ===
    powershell -NoProfile -Command "Get-Content 'C:\Users\simon\WebstormProjects\pc-builder\master-update.log' -Tail 3 -ErrorAction SilentlyContinue"
) else (
    echo Waiting for status file...
)

timeout /t 5 /nobreak >nul
goto loop
