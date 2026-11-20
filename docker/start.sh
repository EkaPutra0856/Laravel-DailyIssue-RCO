#!/bin/sh
# Dijalankan setiap container start: siapkan config, migrasi database, lalu jalankan Apache.
set -e

# Render "generateValue" menghasilkan base64 tanpa awalan; Laravel butuh "base64:...".
case "$APP_KEY" in
    base64:*|"") ;;
    *) export APP_KEY="base64:$APP_KEY" ;;
esac

# Disk Render bisa kosong (persistent disk baru), pastikan folder storage ada.
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private storage/app/public
chown -R www-data:www-data storage bootstrap/cache

php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force

exec apache2-foreground
