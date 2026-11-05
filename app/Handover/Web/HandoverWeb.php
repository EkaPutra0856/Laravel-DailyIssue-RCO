<?php

namespace App\Handover\Web;

use App\Handover\Category;
use App\Handover\Dates;
use App\Handover\Issue;
use App\Handover\IssueService;
use App\Handover\Query;
use App\Handover\Report;
use App\Handover\Sources\Source;
use App\Handover\Sources\SourceError;
use App\Handover\Teams;
use App\Handover\Telegram;
use App\Handover\TelegramError;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Logika versi web: ambil issue (dengan cache), link ke sheet, susun & kirim pesan Telegram. */
final class HandoverWeb
{
    public const VERSION = '1.0.0';
    public const CACHE_SECONDS = 60;
    // Data lebih tua dari CACHE_SECONDS tapi di bawah ini ditampilkan dulu (updating: true),
    // lalu browser mengambil versi barunya dengan refresh=1 tanpa animasi loading.
    public const STALE_SECONDS = 15 * 60;
    public const GID_CACHE_SECONDS = 600;
    public const MAX_DAYS = 400;
    // Daftar tab di halaman htmlview spreadsheet publik: {name: "Issue Oktober 2026", pageUrl: "...", gid: "352493838"
    private const GID_RE = '#name: "((?:[^"\\\\]|\\\\.)*)", pageUrl: "[^"]*", gid: "(\d+)"#';

    private Telegram $telegram;

    private ?Source $source = null;

    public function __construct()
    {
        $this->telegram = new Telegram((string) config('handover.telegram_token'));
    }

    // Dibuat saat dipakai, supaya halaman & /api/config tetap jalan walau sumber belum dikonfigurasi.
    private function source(): Source
    {
        try {
            return $this->source ??= app(Source::class);
        } catch (SourceError $err) {
            throw new WebError('Akses spreadsheet bermasalah: '.$err->getMessage(), 502);
        }
    }

    /** Detik sejak epoch (pecahan), dari jam Carbon supaya bisa digeser di tes. */
    private static function now(): float
    {
        return CarbonImmutable::now()->getPreciseTimestamp() / 1_000_000;
    }

    /** Buang token/key dari pesan error sebelum ditampilkan atau dicatat. */
    public function scrub(string $text): string
    {
        foreach ([config('handover.apps_script_key'), config('handover.telegram_token'), config('handover.web.password')] as $secret) {
            if ($secret) {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    public function today(): CarbonImmutable
    {
        $now = CarbonImmutable::now(config('handover.timezone'));

        return Dates::make($now->year, $now->month, $now->day);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public static function parseRange(?string $start, ?string $end): array
    {
        $s = Dates::fromIso((string) $start);
        $e = Dates::fromIso((string) $end);
        if (! $s || ! $e) {
            throw new WebError('Tanggal tidak valid (format YYYY-MM-DD)');
        }
        if ($e->lessThan($s)) {
            throw new WebError('Tanggal akhir lebih awal dari tanggal mulai');
        }
        if ($s->diffInDays($e) > self::MAX_DAYS) {
            throw new WebError('Rentang maksimal '.self::MAX_DAYS.' hari');
        }

        return [$s, $e];
    }

    // ---------- data ----------

    private static function cacheKey(CarbonImmutable $s, CarbonImmutable $e): string
    {
        return 'handover.issues.'.$s->toDateString().'.'.$e->toDateString();
    }

    /**
     * Data periode start..end.
     * stale_ok: data yang lebih tua dari CACHE_SECONDS (tapi < STALE_SECONDS) langsung dikembalikan dengan
     * tanda $stale = true. Dipakai tabel; pesan Telegram selalu memakai data segar.
     *
     * @return array{0: array{t: float, issues: Issue[], warnings: string[]}, 1: bool} [data, stale]
     */
    public function load(CarbonImmutable $s, CarbonImmutable $e, bool $refresh = false, bool $staleOk = false): array
    {
        $hit = Cache::get(self::cacheKey($s, $e));
        if ($hit && ! $refresh) {
            $age = self::now() - $hit['t'];
            if ($age < self::CACHE_SECONDS) {
                return [$hit, false];
            }
            if ($staleOk && $age < self::STALE_SECONDS) {
                return [$hit, true];
            }
        }

        return [$this->fetch($s, $e), false];
    }

    private function fetch(CarbonImmutable $s, CarbonImmutable $e): array
    {
        $key = self::cacheKey($s, $e);
        $requested = self::now();
        try {
            return Cache::lock("{$key}.lock", 180)->block(180, function () use ($key, $requested, $s, $e) {
                // Request lain mengambil data yang sama selagi kita menunggu kunci: pakai hasilnya.
                $hit = Cache::get($key);
                if ($hit && $hit['t'] >= $requested) {
                    return $hit;
                }
                try {
                    [$issues, $warnings] = (new IssueService($this->source()))
                        ->collect(new Query($s, $e, Category::cases()));
                } catch (SourceError $err) {
                    throw new WebError('Akses spreadsheet bermasalah: '.$this->scrub($err->getMessage()), 502);
                }
                $entry = ['t' => self::now(), 'issues' => $issues, 'warnings' => $warnings];
                Cache::put($key, $entry, self::STALE_SECONDS);

                return $entry;
            });
        } catch (LockTimeoutException) {
            throw new WebError('Server sedang sibuk mengambil data. Coba lagi sebentar.', 503);
        }
    }

    private function linkSpreadsheetId(): string
    {
        return config('handover.web.link_target') === 'transit'
            ? (string) config('handover.web.transit_spreadsheet_id')
            : (string) config('handover.spreadsheet_id');
    }

    /** @return array<string, string> */
    private function readGids(): array
    {
        if (config('handover.web.link_target') === 'transit') {
            // Transit publik: gid dibaca dari halaman htmlview tanpa login.
            $id = config('handover.web.transit_spreadsheet_id');
            $html = Http::timeout(20)->get("https://docs.google.com/spreadsheets/d/{$id}/htmlview")->throw()->body();
            preg_match_all(self::GID_RE, $html, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as [, $name, $gid]) {
                $out[self::unescapeJs($name)] = $gid;
            }

            return $out;
        }

        // Sheet asli tidak publik: gid dari Web App Apps Script.
        return $this->source()->sheetGids();
    }

    private static function unescapeJs(string $s): string
    {
        $s = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', fn ($m) => mb_chr(hexdec($m[1]), 'UTF-8'), $s);

        return preg_replace('/\\\\(.)/su', '$1', $s);
    }

    /** @return array<string, string> */
    public function sheetGids(): array
    {
        $key = 'handover.gids.'.config('handover.web.link_target');
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit;
        }
        try {
            $gids = $this->readGids();
            Cache::put($key, $gids, self::GID_CACHE_SECONDS);
            Cache::forever("{$key}.last", $gids);

            return $gids;
        } catch (SourceError|ConnectionException|RequestException $err) {
            Log::warning('Daftar tab (gid) tidak bisa dibaca: '.$this->scrub($err->getMessage()));
            $last = Cache::get("{$key}.last", []);
            Cache::put($key, $last, 60);  // coba lagi 1 menit lagi, bukan 10 menit

            return $last;
        }
    }

    public function sheetUrl(): string
    {
        return "https://docs.google.com/spreadsheets/d/{$this->linkSpreadsheetId()}/edit";
    }

    public function rowLink(string $sheet, int $row, array $gids): string
    {
        $gid = $gids[$sheet] ?? null;

        return $gid ? "{$this->sheetUrl()}#gid={$gid}&range=A{$row}" : $this->sheetUrl();
    }

    public function issuesPayload(?string $start, ?string $end, bool $refresh = false): array
    {
        [$s, $e] = self::parseRange($start, $end);
        [$data, $stale] = $this->load($s, $e, $refresh, staleOk: true);
        $gids = $this->sheetGids();
        $tz = config('handover.timezone');

        return [
            'start' => $s->toDateString(),
            'end' => $e->toDateString(),
            // True: data ini dari cache dan versi barunya belum diambil (browser mengambilnya sebentar lagi).
            'updating' => $stale,
            'fetched_at' => CarbonImmutable::createFromTimestamp($data['t'], $tz)->format('Y-m-d\TH:i:sP'),
            'warnings' => $data['warnings'],
            'issues' => array_map(fn (Issue $i) => [
                'key' => $i->key(),
                'sheet' => $i->sheet,
                'issue_no' => $i->issueNo,
                'tanggal' => $i->tanggal->toDateString(),
                'team' => $i->team,
                'aplikasi' => $i->aplikasi,
                'status' => $i->status,
                'pic' => $i->pic,
                'label' => $i->label(),
                'category' => $i->category->value,
                'done_reason' => $i->doneReason,
                'waiting_external' => $i->waitingExternal,
                'link' => $this->rowLink($i->sheet, $i->issueNo, $gids),
            ], $data['issues']),
        ];
    }

    // ---------- pesan ----------

    /** @return string[] */
    public function messages(array $body): array
    {
        [$s, $e] = self::parseRange($body['start'] ?? null, $body['end'] ?? null);
        $wanted = [];
        foreach ((array) ($body['categories'] ?? []) as $c) {
            $wanted[] = Category::tryFrom((string) $c) ?? throw new WebError('Kategori tidak dikenal');
        }
        $cats = array_values(array_filter(Category::cases(), fn ($c) => in_array($c, $wanted, true)));
        if (! $cats) {
            throw new WebError('Pilih minimal satu kategori');
        }
        $chosen = (array) ($body['teams'] ?? []) ?: Teams::ALL;
        $teams = array_values(array_filter(Teams::ALL, fn ($t) => in_array($t, $chosen, true)));
        if (! $teams) {
            throw new WebError('Pilih minimal satu tim');
        }

        $query = new Query($s, $e, $cats, detail: (bool) ($body['detail'] ?? false), split: (bool) ($body['split'] ?? false),
            teams: count($teams) === count(Teams::ALL) ? null : $teams);
        [$data] = $this->load($s, $e);
        $issues = array_values(array_filter($data['issues'], fn (Issue $i) => in_array($i->team, $teams, true)));
        if (array_key_exists('keys', $body) && $body['keys'] !== null) {
            // Hanya baris yang sedang tampil di tabel (hasil pencarian / filter lain di browser).
            $keyset = array_flip(array_map('strval', (array) $body['keys']));
            $issues = array_values(array_filter($issues, fn (Issue $i) => isset($keyset[$i->key()])));
        }

        return Report::buildMessages($issues, $query, (string) config('handover.report_title'), $data['warnings']);
    }

    public function targetChatId(): ?int
    {
        $id = trim((string) config('handover.web.chat_id'));
        if ($id !== '') {
            return (int) $id;
        }
        $ids = config('handover.allowed_chat_ids');

        return $ids ? min($ids) : null;
    }

    public function chatInfo(): ?array
    {
        $chatId = $this->targetChatId();
        if ($chatId === null || ! $this->telegram->hasToken()) {
            return null;
        }
        $key = "handover.chat_title.{$chatId}";
        $title = Cache::get($key);
        if ($title === null) {
            try {
                $res = $this->telegram->call('getChat', ['chat_id' => $chatId], 15) ?: [];
                $title = $res['title'] ?? $res['username'] ?? $res['first_name'] ?? '';
                Cache::put($key, $title, 86400);
            } catch (TelegramError) {
                return ['id' => $chatId, 'title' => ''];  // coba lagi di request berikutnya
            }
        }

        return ['id' => $chatId, 'title' => $title];
    }

    public function send(array $body): array
    {
        $chat = $this->chatInfo();
        if ($chat === null) {
            throw new WebError('Tujuan Telegram belum diatur (isi WEB_CHAT_ID atau ALLOWED_CHAT_IDS di .env)', 409);
        }
        $msgs = $this->messages($body);
        $total = count($msgs);
        foreach ($msgs as $n => $text) {
            $no = $n + 1;
            try {
                $this->telegram->sendHtml($chat['id'], $text);
            } catch (TelegramError $err) {
                throw $err->description === null
                    ? new WebError("Gagal menghubungi Telegram (pesan {$no}/{$total}). Coba lagi.", 502)
                    : new WebError("Telegram menolak pesan {$no}/{$total}: {$err->description}", 502);
            }
        }
        Log::info("Web: {$total} pesan dikirim ke chat {$chat['id']}");

        return ['sent' => $total, 'chat' => $chat];
    }

    public function config(): array
    {
        return [
            'version' => self::VERSION,
            'title' => config('handover.report_title'),
            'today' => $this->today()->toDateString(),
            'teams' => Teams::ALL,
            'chat' => $this->chatInfo(),
            'sheet_url' => $this->sheetUrl(),
            'cache_seconds' => self::CACHE_SECONDS,
        ];
    }
}
