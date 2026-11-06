# Ambil data terbaru dari spreadsheet asli ke database. Spreadsheet hanya dibaca.
# Issue baru ditambahkan; issue yang berubah di sheet diperbarui, kecuali yang sudah diubah/dihapus lewat web.
#
#   powershell -ExecutionPolicy Bypass -File update-data.ps1                  (bulan ini + bulan lalu)
#   powershell -ExecutionPolicy Bypass -File update-data.ps1 -Sejak 2026-01   (mulai bulan tertentu)
param([string]$Sejak = (Get-Date).AddMonths(-1).ToString("yyyy-MM"))

Set-Location $PSScriptRoot
php artisan handover:import --sinkron "--sejak=$Sejak"
exit $LASTEXITCODE
