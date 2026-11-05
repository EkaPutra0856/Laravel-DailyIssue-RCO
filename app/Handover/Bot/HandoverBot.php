<?php

namespace App\Handover\Bot;

use App\Handover\CommandError;
use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\Report;
use App\Handover\Sources\Source;
use App\Handover\Sources\SourceError;
use App\Handover\Telegram;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Handler pesan Telegram (setara handover_bot/bot.py). */
final class HandoverBot
{
    public const MENU = [
        ['rekap', 'Rekap pending + on progress'],
        ['pending', 'Hanya issue belum tersentuh'],
        ['progress', 'Hanya issue on going'],
        ['done', 'Hanya issue selesai'],
        ['doneprogress', 'Done & on progress (2 bubble)'],
        ['rekapdetail', 'Rekap + nama aplikasi'],
        ['help', 'Panduan'],
        ['id', 'Tampilkan chat id'],
    ];

    public function __construct(private Telegram $telegram) {}

    private function allowed(int $chatId): bool
    {
        $ids = config('handover.allowed_chat_ids');

        return ! $ids || in_array($chatId, $ids, true);
    }

    private function reply(int $chatId, string $text): void
    {
        $this->telegram->sendHtml($chatId, $text);
    }

    private function denied(int $chatId): void
    {
        Log::warning("Chat {$chatId} ditolak (tidak ada di ALLOWED_CHAT_IDS)");
        $this->reply($chatId, "⛔ Chat ini belum diizinkan. Chat id: <code>{$chatId}</code>");
    }

    /** Proses satu update getUpdates. Perintah untuk bot lain (/rekap@BotLain) dan teks biasa diabaikan. */
    public function handleUpdate(array $update, ?string $botUsername = null): void
    {
        $msg = $update['message'] ?? null;
        $text = $msg['text'] ?? null;
        if (! is_string($text) || ! preg_match('#(*UCP)^/(\w+)(?:@(\w+))?(?:\s|$)#u', $text, $m)) {
            return;
        }
        $chatId = (int) $msg['chat']['id'];
        $mention = $m[2] ?? '';
        if ($mention !== '' && $botUsername && strcasecmp($mention, $botUsername) !== 0) {
            return;
        }
        $cmd = mb_strtolower($m[1]);

        try {
            match (true) {
                in_array($cmd, ['start', 'help'], true) => $this->allowed($chatId)
                    ? $this->reply($chatId, Report::helpText()) : $this->denied($chatId),
                $cmd === 'id' => $this->reply($chatId, "Chat id: <code>{$chatId}</code>"),
                isset(Commands::all()[$cmd]) => $this->rekap($chatId, $text),
                default => null,
            };
        } catch (Throwable $e) {
            Log::error("Error saat memproses update: {$e->getMessage()}", ['exception' => $e]);
            $this->reply($chatId, '❌ Terjadi error saat membuat rekap. Cek log bot.');
        }
    }

    private function rekap(int $chatId, string $text): void
    {
        if (! $this->allowed($chatId)) {
            $this->denied($chatId);

            return;
        }
        $now = CarbonImmutable::now(config('handover.timezone'));
        try {
            $query = Commands::parse($text, Dates::make($now->year, $now->month, $now->day));
        } catch (CommandError $e) {
            $this->reply($chatId, "❌ {$e->getMessage()}");

            return;
        }

        Log::info(sprintf('Chat %d: %s -> %s..%s %s', $chatId, json_encode($text, JSON_UNESCAPED_UNICODE),
            $query->start->toDateString(), $query->end->toDateString(),
            implode(',', array_map(fn ($c) => $c->value, $query->categories))));
        try {
            $this->telegram->call('sendChatAction', ['chat_id' => $chatId, 'action' => 'typing']);
        } catch (Throwable) {
            // indikator "mengetik" tidak penting
        }
        try {
            [$issues, $warnings] = (new IssueService(app(Source::class)))->collect($query);
        } catch (SourceError $e) {
            Log::error("Akses Google: {$e->getMessage()}");
            $this->reply($chatId, "❌ Akses spreadsheet bermasalah: {$e->getMessage()}");

            return;
        }

        foreach (Report::buildMessages($issues, $query, (string) config('handover.report_title'), $warnings) as $msg) {
            $this->reply($chatId, $msg);
        }
    }
}
