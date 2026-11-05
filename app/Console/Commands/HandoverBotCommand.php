<?php

namespace App\Console\Commands;

use App\Handover\Bot\HandoverBot;
use App\Handover\Telegram;
use App\Handover\TelegramError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Jalankan bot Telegram dengan long polling (tidak butuh URL publik / webhook). Stop: Ctrl+C. */
class HandoverBotCommand extends Command
{
    protected $signature = 'handover:bot';

    protected $description = 'Jalankan bot Telegram Handover (/rekap, /pending, ...) dengan long polling';

    public function handle(): int
    {
        $token = (string) config('handover.telegram_token');
        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN belum diisi di .env');

            return 2;  // salah konfigurasi: run-bot.ps1 tidak restart
        }
        $telegram = new Telegram($token);
        $bot = new HandoverBot($telegram);

        // Polling butuh webhook dimatikan (sama seperti python-telegram-bot run_polling).
        $telegram->call('deleteWebhook');
        $telegram->call('setMyCommands', ['commands' => array_map(
            fn ($c) => ['command' => $c[0], 'description' => $c[1]], HandoverBot::MENU)]);
        $me = $telegram->call('getMe');
        $this->info("Bot @{$me['username']} siap. Stop: Ctrl+C");
        Log::info("Bot @{$me['username']} siap");

        $offset = 0;
        while (true) {
            try {
                $updates = $telegram->call('getUpdates',
                    ['offset' => $offset, 'timeout' => 50, 'allowed_updates' => ['message']], timeout: 70);
            } catch (TelegramError $e) {
                // "Conflict: terminated by other getUpdates request" = bot yang sama jalan di komputer lain.
                $this->warn($e->getMessage());
                Log::warning($e->getMessage());
                sleep(5);

                continue;
            }
            foreach ($updates as $update) {
                $offset = $update['update_id'] + 1;
                try {
                    $bot->handleUpdate($update, $me['username'] ?? null);
                } catch (Throwable $e) {
                    Log::error("Gagal memproses update: {$e->getMessage()}", ['exception' => $e]);
                }
            }
        }
    }
}
