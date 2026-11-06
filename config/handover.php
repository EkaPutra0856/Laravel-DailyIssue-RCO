<?php

$ids = fn (?string $raw) => array_values(array_map('intval', array_filter(
    array_map('trim', explode(',', str_replace(';', ',', (string) $raw))),
    fn ($x) => $x !== '',
)));

return [
    // Token dari @BotFather
    'telegram_token' => env('TELEGRAM_BOT_TOKEN', ''),

    // Web App Apps Script yang membaca sheet asli (hanya aksi baca).
    'apps_script_url' => env('APPS_SCRIPT_URL', ''),
    'apps_script_key' => env('APPS_SCRIPT_KEY', ''),
    'spreadsheet_id' => env('SPREADSHEET_ID', '1lmIgK_GMZaeRUUc0Ea_K31jpRAn_P_PifWsm5PvjJsw'),

    // Chat yang boleh memakai bot. Kosong = semua chat boleh.
    'allowed_chat_ids' => $ids(env('ALLOWED_CHAT_IDS', '')),

    'timezone' => env('HANDOVER_TIMEZONE', env('TIMEZONE', 'Asia/Jakarta')),
    'report_title' => env('REPORT_TITLE', 'Handover Polisi – Team RCO'),

    'web' => [
        // Kosong = tanpa login. Wajib diisi kalau web bisa dibuka dari jaringan.
        'password' => env('WEB_PASSWORD', ''),
        // Tujuan tombol "Kirim ke Tele"; kosong = chat pertama di ALLOWED_CHAT_IDS
        'chat_id' => env('WEB_CHAT_ID', ''),
        // Tujuan link "buka baris di sheet": asli (sheet sumber, butuh akses) atau transit (cermin publik)
        'link_target' => strtolower((string) env('WEB_LINK_TARGET', 'asli')),
        'transit_spreadsheet_id' => env('TRANSIT_SPREADSHEET_ID', '1UK5iVxAi8ROiUHGIjGeMLeMZAOUAh-nRRb75eb3F59A'),
        // Web portal RCO: link "Home" di sidebar, dan asal (origin) yang boleh menampilkan halaman ini di iframe.
        'portal_url' => env('PORTAL_URL', ''),
        // Kunci bersama dengan portal untuk masuk lewat /sso. Kosong = tanpa pemeriksaan (pengembangan lokal).
        'sso_secret' => env('PORTAL_SSO_SECRET', ''),
        'portal_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('PORTAL_ORIGIN', ''))))),
    ],
];
