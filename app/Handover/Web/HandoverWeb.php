<?php

namespace App\Handover\Web;

use App\Handover\Category;
use App\Handover\Dates;
use App\Handover\Issue;
use App\Handover\IssueProvider;
use App\Handover\Query;
use App\Handover\Report;
use App\Handover\Sources\SourceError;
use App\Handover\Teams;
use App\Handover\Telegram;
use App\Handover\TelegramError;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Logika dashboard rekap: ambil issue dari database, susun & kirim pesan Telegram. */
final class HandoverWeb
{
    public const VERSION = '2.0.0';
    public const MAX_DAYS = 400;

    private Telegram $telegram;

    public function __construct(private IssueProvider $provider)
    {
        $this->telegram = new Telegram((string) config('handover.telegram_token'));
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

    /** @return array{0: Issue[], 1: string[]} */
    public function load(CarbonImmutable $s, CarbonImmutable $e): array
    {
        try {
            return $this->provider->collect(new Query($s, $e, Category::cases()));
        } catch (SourceError $err) {
            throw new WebError('Akses data bermasalah: '.$this->scrub($err->getMessage()), 502);
        }
    }

    /** Link "buka issue" di tabel rekap: halaman Daily Issue dengan popup issue itu terbuka. */
    public function rowLink(Issue $i): string
    {
        return rtrim(url('/'), '/').'/?issue='.($i->ref ?? $i->issueNo);
    }

    public function issuesPayload(?string $start, ?string $end): array
    {
        [$s, $e] = self::parseRange($start, $end);
        [$issues, $warnings] = $this->load($s, $e);

        return [
            'start' => $s->toDateString(),
            'end' => $e->toDateString(),
            'updating' => false,
            'fetched_at' => CarbonImmutable::now(config('handover.timezone'))->format('Y-m-d\TH:i:sP'),
            'warnings' => $warnings,
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
                'waiting_external' => $i->waitingExternal || $i->trouble,
                'link' => $this->rowLink($i),
            ], $issues),
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
        [$issues, $warnings] = $this->load($s, $e);
        $issues = array_values(array_filter($issues, fn (Issue $i) => in_array($i->team, $teams, true)));
        if (array_key_exists('keys', $body) && $body['keys'] !== null) {
            // Hanya baris yang sedang tampil di tabel (hasil pencarian / filter lain di browser).
            $keyset = array_flip(array_map('strval', (array) $body['keys']));
            $issues = array_values(array_filter($issues, fn (Issue $i) => isset($keyset[$i->key()])));
        }

        return Report::buildMessages($issues, $query, (string) config('handover.report_title'), $warnings);
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
            'sheet_url' => url('/'),  // tombol header membuka halaman Daily Issue
            'cache_seconds' => 0,
        ];
    }
}
