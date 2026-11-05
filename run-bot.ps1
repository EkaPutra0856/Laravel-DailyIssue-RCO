# Jalankan bot Telegram. Otomatis restart kalau crash. Stop: Ctrl+C.
Set-Location $PSScriptRoot

while ($true) {
    php artisan handover:bot
    if ($LASTEXITCODE -eq 0 -or $LASTEXITCODE -eq 2) { break }
    Write-Host "Bot berhenti (exit $LASTEXITCODE). Restart dalam 10 detik..."
    Start-Sleep -Seconds 10
}
