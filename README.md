# Handover RCO – Versi Laravel

Versi Laravel dari [Bot Handover – Team RCO](https://github.com/EkaPutra0856/Handover-Bot-RCO) (Python).
Isinya sama: **dashboard web** dan **bot Telegram** yang merekap issue dari spreadsheet
**Issue &lt;Bulan&gt; &lt;Tahun&gt;**, yaitu pending (putih), on progress (kuning), dan done (hijau).

Data issue sekarang disimpan di **database MySQL**, tidak lagi di spreadsheet:

- **Daily Issue RCO** (`/`): polisi mencatat issue lewat popup (status otomatis Pending), engineer
  menindaklanjuti lewat popup (status, PIC, waktu mulai, tanggal finish, waktu selesai). Submit engineer hanya
  bisa kalau PIC terisi dan status On Progress / Trouble / Done. Nama aplikasi otomatis mengisi Team.
- **Rekap Handover** (`/rekap`), bot `/rekap`, dan `handover:rekap` membaca database yang sama.
- Issue lama disalin sekali dari spreadsheet dengan `handover:import` (spreadsheet hanya dibaca).

Aturan rekap dan format pesan di-porting dari versi Python dan sudah dicocokkan dengan data asli.

## Instalasi

Butuh **PHP 8.2+** (ekstensi curl, mbstring, openssl, fileinfo) dan **Composer**. XAMPP atau Laragon sudah
cukup.

1. Clone dan install dependency:

   ```powershell
   git clone <url-repo> Handover-RCO-Laravel
   cd Handover-RCO-Laravel
   composer install
   ```

2. Buat `.env` dan app key:

   ```powershell
   Copy-Item .env.example .env
   php artisan key:generate
   ```

3. Isi bagian **Handover RCO** di `.env`: minimal `TELEGRAM_BOT_TOKEN`, `APPS_SCRIPT_URL`, dan `APPS_SCRIPT_KEY`.
   Nilainya sama dengan `.env` versi Python. `.env` berisi token dan key, jadi **tidak ikut ke Git** dan jangan
   dikirim ke grup.

4. Siapkan database MySQL (Laragon: klik **Start All**; XAMPP: Start MySQL), lalu buat database dan tabelnya:

   ```powershell
   & "C:\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe" -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS handover_rco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   php artisan migrate
   ```

   Koneksi diatur di `.env` (`DB_HOST=127.0.0.1`, `DB_DATABASE=handover_rco`, `DB_USERNAME=root`).

5. Salin issue lama dari spreadsheet (sekali saja; default 3 bulan terakhir, sheet asli hanya dibaca):

   ```powershell
   php artisan handover:import
   php artisan handover:import --sejak=2026-01 --sampai=2026-11
   ```

   Aman dijalankan ulang: baris yang sudah diimpor dilewati, isian engineer dari web tidak tertimpa
   (kecuali pakai `--timpa`). Perintah ini juga mengisi daftar Aplikasi -> Team untuk rekomendasi.

6. Cek instalasi (tes memakai database sementara di memori, tidak butuh MySQL/Google/Telegram):

   ```powershell
   php artisan test
   ```

Cache dan sesi disimpan di `storage/framework` (`CACHE_STORE=file`).

## Menjalankan

Semua perintah dijalankan dari folder proyek.

**Tes rekap di terminal** (tidak mengirim apa pun ke Telegram):

```powershell
php artisan handover:rekap "/rekap bulan ini" --stats
```

**Versi web**, lalu buka http://localhost:8091:

```powershell
powershell -ExecutionPolicy Bypass -File run-web.ps1
```

Biarkan terminal tetap terbuka; berhenti dengan Ctrl+C. `php artisan serve` cocok untuk pemakaian di PC sendiri.
Untuk dibuka banyak orang, pasang di Apache/Nginx (misalnya lewat Laragon) dengan document root folder `public/`,
dan isi `WEB_PASSWORD`.

**Bot Telegram**, di terminal terpisah:

```powershell
powershell -ExecutionPolicy Bypass -File run-bot.ps1
```

Bot memakai long polling, jadi tidak butuh URL publik. **Bot hanya boleh jalan di satu tempat**: satu token bot
hanya bisa dipakai satu program, jadi matikan dulu bot versi Python (atau n8n lama). Kalau lupa, log bot
menampilkan `Conflict: terminated by other getUpdates request`. Versi web boleh jalan bersamaan dengan versi Python.

## Daily Issue di web portal

- Peran tanpa login (portal sudah login): pilih Polisi/Engineer + nama di pojok kanan atas, atau portal
  mengirimnya lewat link: `/?role=engineer&nama=Hendrik`.
- Ditampilkan di dalam portal (iframe): pakai `/?embed=1` supaya topbar & sidebar halaman ini disembunyikan.
  Alamat portal wajib dicantumkan di `PORTAL_ORIGIN` (contoh `http://172.18.66.243`); selain itu browser menolak
  menampilkan halaman ini di iframe. `PORTAL_URL` dipakai untuk menu **Home** di sidebar.
- Buka issue tertentu langsung: `/?issue=3129` (link di tabel Rekap memakai ini).
- Catatan: tanpa login, peran hanya dibedakan di tampilan. Akses tetap dijaga oleh portal / `WEB_PASSWORD`.

## Keamanan sheet asli

Sheet asli **hanya dibaca**:

- Data diambil lewat Web App Apps Script (`apps_script/Code.gs` di repo Python). Proyek ini hanya bisa mengirim
  aksi `titles` dan `fetch` (`AppsScriptSource::READ_ACTIONS`); aksi lain ditolak sebelum menyentuh jaringan.
- Tidak ada kode yang menulis ke spreadsheet. Tes `AppsScriptSourceTest` gagal kalau pengaman ini dilonggarkan.

## Fitur

| Perintah | Isi |
|---|---|
| `/rekap [periode]` | Pending + on progress |
| `/pending`, `/progress`, `/done` | Satu kategori saja |
| `/doneprogress` | Done dan on progress, 2 bubble chat |
| `/rekapdetail` | Rekap + nama aplikasi |
| `/help`, `/id` | Panduan, chat id |

Periode: kosong (hari ini), `kemarin`, `minggu ini`, `bulan ini`, `bulan lalu`, `Oktober 2026`, `13/10/2026`,
`5-7 Oktober 2026`, `28 September 2026 : 2 Oktober 2026`, `September : November 2026`. Tambahan: `detail`,
`pisah`, dan nama kategori. Aturan lengkapnya sama dengan README versi Python.

**Web:** filter periode/tim/kategori/pencarian, tabel dengan link langsung ke baris di sheet asli, status
pengambilan data di header tabel, export CSV, dan Export ke Chat (dengan pratinjau).

## Konfigurasi `.env`

| Variabel | Default | Keterangan |
|---|---|---|
| `TELEGRAM_BOT_TOKEN` | – | wajib untuk bot dan Export ke Chat |
| `APPS_SCRIPT_URL` | – | URL Web App `/exec` |
| `APPS_SCRIPT_KEY` | – | sama dengan `SECRET_KEY` di Apps Script |
| `SPREADSHEET_ID` | ID sheet asli | untuk link |
| `ALLOWED_CHAT_IDS` | kosong (semua) | chat yang boleh memakai bot, pisahkan koma |
| `REPORT_TITLE` | `Handover Polisi – Team RCO` | pakai tanda kutip |
| `HANDOVER_TIMEZONE` | `Asia/Jakarta` | untuk "hari ini" |
| `WEB_PASSWORD` | kosong | login browser (username bebas). **Wajib** kalau web dibuka dari jaringan |
| `WEB_CHAT_ID` | chat pertama `ALLOWED_CHAT_IDS` | tujuan Export ke Chat |
| `WEB_LINK_TARGET` | `asli` | link baris: `asli` (butuh akses) atau `transit` (cermin publik) |
| `TRANSIT_SPREADSHEET_ID` | ID transit | dipakai kalau `WEB_LINK_TARGET=transit` |

## Struktur kode

```
app/Handover/
  Commands.php          parse perintah & periode
  Dates.php             tanggal Indonesia, serial Sheets
  Rules.php             deteksi kolom, warna, tim, sanitasi PIC, klasifikasi
  IssueService.php      pilih tab per bulan, gabungkan, koreksi & filter tanggal
  Report.php            format pesan HTML + pecah pesan
  Py.php                padanan perilaku string Python (strip, len, escape) supaya hasil identik
  Sources/              AppsScriptSource (read-only, retry, cache daftar tab)
  Web/HandoverWeb.php   cache data, link ke sheet, pratinjau & kirim Telegram
  Bot/HandoverBot.php   handler perintah Telegram
  Telegram.php          klien Bot API
app/Console/Commands/   handover:rekap, handover:bot
app/Http/               controller + middleware (password, header keamanan, POST wajib JSON)
resources/views/        dashboard.blade.php
public/static/          css, js, ikon dashboard
tests/                  PHPUnit (porting tes Python + tes web & bot)
```
