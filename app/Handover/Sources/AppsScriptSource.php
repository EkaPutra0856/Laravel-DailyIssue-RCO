<?php

namespace App\Handover\Sources;

use App\Handover\RawRow;
use App\Handover\RawSheet;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

/**
 * Baca spreadsheet lewat Apps Script Web App (apps_script/Code.gs di proyek Python) yang jalan sebagai pemilik akses.
 * Hanya aksi baca yang bisa dikirim; sheet asli tidak pernah ditulis.
 */
final class AppsScriptSource implements Source
{
    // Bot & web hanya boleh membaca. Aksi lain di Web App (sync/verify transit) sengaja tidak bisa dipanggil.
    public const READ_ACTIONS = ['titles', 'fetch'];
    public const CONNECT_TIMEOUT = 15;
    public const TIMEOUT = 120;  // baca tab besar bisa hampir 1 menit
    // Koneksi ke Google kadang putus/timeout sesaat. Aman diulang karena semua aksi hanya membaca.
    public const RETRIES = 3;
    public const TITLES_CACHE_SECONDS = 600;

    public function __construct(
        private string $url,
        private string $key,
    ) {
        if ($url === '' || $key === '') {
            throw new SourceError('APPS_SCRIPT_URL dan APPS_SCRIPT_KEY wajib diisi di .env');
        }
    }

    public function call(string $action, array $params = []): array
    {
        if (! in_array($action, self::READ_ACTIONS, true)) {
            throw new SourceError("Aksi '{$action}' diblokir: bot hanya boleh membaca spreadsheet");
        }
        for ($attempt = 1; ; $attempt++) {
            try {
                $resp = Http::connectTimeout(self::CONNECT_TIMEOUT)->timeout(self::TIMEOUT)
                    ->get($this->url, ['key' => $this->key, 'action' => $action, ...$params])
                    ->throw();
                $data = json_decode($resp->body(), true, 512, JSON_THROW_ON_ERROR);
                break;
            } catch (ConnectionException $e) {
                // Waktu baca habis (Apps Script masih bekerja) tidak diulang; gagal sambung diulang.
                if (str_contains($e->getMessage(), 'Operation timed out')) {
                    throw new SourceError('Apps Script terlalu lama menjawab. Coba lagi.', 0, $e);
                }
                Log::warning(sprintf('Apps Script tidak terjangkau (percobaan %d/%d)', $attempt, self::RETRIES));
                if ($attempt >= self::RETRIES) {
                    throw new SourceError(sprintf(
                        'Server Google (Apps Script) tidak bisa dihubungi setelah %dx percobaan. '
                        .'Cek koneksi internet, lalu coba Refresh.', self::RETRIES), 0, $e);
                }
                usleep(2_000_000 * $attempt);
            } catch (RequestException $e) {
                throw new SourceError('Gagal memanggil Apps Script: HTTP '.$e->response->status(), 0, $e);
            } catch (JsonException $e) {
                throw new SourceError('Gagal memanggil Apps Script: respons bukan JSON', 0, $e);
            }
        }
        if (! is_array($data) || empty($data['ok'])) {
            throw new SourceError('Apps Script menolak: '.($data['error'] ?? 'tanpa keterangan'));
        }

        return $data;
    }

    private function cacheKey(): string
    {
        return 'handover.titles.'.md5($this->url);
    }

    // Tab hanya bertambah sebulan sekali: tidak perlu dibaca ulang tiap load (hemat 5-8 detik).
    private function titles(): array
    {
        return Cache::remember($this->cacheKey(), self::TITLES_CACHE_SECONDS, fn () => $this->call('titles'));
    }

    public function forgetTitles(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function listTitles(): array
    {
        return $this->titles()['titles'];
    }

    /** gid tiap tab (untuk link ke baris). Web App lama tanpa field gids -> kosong. */
    public function sheetGids(): array
    {
        $out = [];
        foreach ($this->titles()['gids'] ?? [] as $name => $gid) {
            $out[(string) $name] = (string) $gid;
        }

        return $out;
    }

    public function fetch(array $titles): array
    {
        if (! $titles) {
            return [];
        }
        $data = $this->call('fetch', ['tabs' => json_encode(array_values($titles), JSON_UNESCAPED_UNICODE)]);
        $out = [];
        foreach ($data['sheets'] as $sh) {
            $bgs = $sh['backgrounds'] ?? [];
            // Web App baru melewati baris kosong dan mengirim nomor baris aslinya;
            // versi lama mengirim semua baris mulai baris 1.
            $numbers = $sh['rows'] ?? [] ?: range(1, max(count($sh['values']), 1));
            $rows = [];
            foreach (array_values($sh['values']) as $i => $vals) {
                if (! isset($numbers[$i])) {
                    break;
                }
                $rows[] = new RawRow(
                    rowNumber: (int) $numbers[$i],
                    values: array_map(fn ($v) => $v === '' ? null : $v, $vals),
                    colors: array_map(self::hex(...), $bgs[$i] ?? []),
                );
            }
            Log::info(sprintf("Sheet '%s': %d baris", $sh['title'], count($rows)));
            $out[] = new RawSheet((string) $sh['title'], $rows);
        }

        return $out;
    }

    /** @return array{0:int,1:int,2:int}|null  putih dianggap tidak berwarna */
    public static function hex(?string $color): ?array
    {
        if (! $color || ! preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $color, $m)) {
            return null;
        }
        $rgb = [hexdec($m[1]), hexdec($m[2]), hexdec($m[3])];

        return $rgb === [255, 255, 255] ? null : $rgb;
    }
}
