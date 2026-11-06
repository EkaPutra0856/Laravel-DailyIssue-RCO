# Handover RCO – Versi Laravel

Versi Laravel dari [Bot Handover – Team RCO](https://github.com/EkaPutra0856/Handover-Bot-RCO) (Python).
Isinya sama: **dashboard web** dan **bot Telegram** yang merekap issue dari spreadsheet
**Issue &lt;Bulan&gt; &lt;Tahun&gt;**, yaitu pending (putih), on progress (kuning), dan done (hijau).

Data issue sekarang disimpan di **database MySQL**, tidak lagi di spreadsheet:

- **Daily Issue RCO** (`/`): polisi mencatat issue lewat popup (status otomatis Pending), engineer
  menindaklanjuti lewat popup (status, PIC, waktu mulai, tanggal finish, waktu selesai). Submit engineer hanya
  bisa kalau PIC terisi dan status On Progress / Trouble / Done. Nama aplikasi otomatis mengisi Team.
  - Filter periode (hari ini s/d bulan lalu, rentang tanggal, pilih bulan), chip tim & kategori, pencarian,
    panel **Per Tim**, dan **Download Excel** untuk semua peran.
  - Khusus polisi: **Edit** & **Hapus** issue (hapus = soft delete, data tetap di database), dan
    **Kirim ke Telegram** (pratinjau, salin isi chat, kirim) untuk issue yang sedang tampil.
  - Nomor issue mulai dari 1 tiap bulan.
- Bot `/rekap` dan `handover:rekap` membaca database yang sama (format pesan sama dengan Kirim ke Telegram).
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

## Ambil data terbaru dari spreadsheet

Selama tim masih mencatat di spreadsheet, jalankan ini kapan saja untuk menyalin perubahan ke database
(spreadsheet hanya dibaca). Butuh MySQL menyala. Cara paling mudah: **klik dua kali `update-data.bat`**, atau:

```powershell
powershell -ExecutionPolicy Bypass -File update-data.ps1
powershell -ExecutionPolicy Bypass -File update-data.ps1 -Sejak 2026-01
```

Default-nya bulan ini + bulan lalu. Di VS Code: **Terminal → Run Task → Ambil data terbaru dari spreadsheet**.

- Issue baru di sheet ditambahkan, dan mendapat nomor bulanan berikutnya.
- Issue yang berubah di sheet (status, PIC, dll.) ikut diperbarui.
- Issue yang **sudah diubah lewat web** (kolom `diubah_web_at` terisi) tidak ditimpa, dan issue yang sudah dihapus
  tidak muncul lagi.
- Baris dicocokkan lewat nama tab + nomor baris sheet, jadi jangan sisipkan/hapus baris di tengah sheet.

## Daily Issue di web portal

- Siapa pun yang sudah login di portal boleh memakai fitur ini (lihat "Menyambungkan ke portal" di bawah).
  Peran dipilih sendiri di pojok kanan atas (Polisi/Engineer), atau lewat link: `/?role=engineer`.
- Ditampilkan di dalam portal (iframe): pakai `/?embed=1` supaya topbar & sidebar halaman ini disembunyikan.
  Alamat portal wajib dicantumkan di `PORTAL_ORIGIN` (contoh `http://172.18.66.243`); selain itu browser menolak
  menampilkan halaman ini di iframe. `PORTAL_URL` dipakai untuk menu **Home** di sidebar.
- Buka issue tertentu langsung (popup terbuka): `/?issue=<id>`.
- Catatan: peran hanya dibedakan di tampilan, server tidak membedakan polisi dan engineer.

### Menyambungkan ke portal

Aplikasi ini terpisah dari portal, jadi portal perlu "mengantar" penggunanya lewat link bertanda tangan. Link
berlaku 5 menit paling lama, dan sesi di sini berlaku sesuai `SESSION_LIFETIME`. Selama `PORTAL_SSO_SECRET` kosong,
pemeriksaan ini mati (untuk pengembangan lokal), jadi **wajib diisi di server**.

1. Buat kunci sekali saja, lalu isi nilai yang sama di `.env` kedua aplikasi:

   ```bash
   php -r "echo bin2hex(random_bytes(32));"
   ```

   ```env
   # .env aplikasi ini
   PORTAL_SSO_SECRET=<kunci>
   PORTAL_URL=http://172.18.66.243/
   PORTAL_ORIGIN=http://172.18.66.243

   # .env portal
   DAILY_ISSUE_URL=http://172.18.66.243:8091
   DAILY_ISSUE_SSO_SECRET=<kunci>
   ```

2. Tambahkan satu route di `routes/web.php` portal, lalu arahkan menu Daily Issue ke `/daily-issue`.
   Sesuaikan `username` dan `name` dengan kolom tabel user portal. Setelah `php artisan config:cache`, `env()` di
   luar folder config bernilai kosong, jadi kalau portal memakainya pindahkan kedua nilai itu ke `config/services.php`.

   ```php
   Route::middleware('auth')->get('/daily-issue', function () {
       $user = auth()->user();
       $data = base64_encode(json_encode([
           'username' => $user->username,
           'nama' => $user->name,
           'exp' => time() + 60,
       ]));
       $sig = hash_hmac('sha256', $data, env('DAILY_ISSUE_SSO_SECRET'));

       return redirect(env('DAILY_ISSUE_URL').'/sso?'.http_build_query(['data' => $data, 'sig' => $sig]));
   });
   ```

   Untuk iframe, tambahkan `'embed' => 1` di `http_build_query`. Portal dan aplikasi ini harus berada di host yang
   sama (boleh beda port); kalau beda host, browser tidak mengirim cookie sesi di dalam iframe.

Tanpa link ini, halaman dialihkan ke `PORTAL_URL` dan semua API (termasuk lampiran dan export) menjawab 401.

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
| `PORTAL_SSO_SECRET` | kosong | kunci bersama dengan portal. Kosong = siapa pun bisa membuka. **Wajib** di server |
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
