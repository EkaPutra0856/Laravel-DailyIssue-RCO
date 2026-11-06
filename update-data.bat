@echo off
rem Klik dua kali untuk mengambil data terbaru dari spreadsheet asli ke database (bulan ini + bulan lalu).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0update-data.ps1" %*
echo.
pause
