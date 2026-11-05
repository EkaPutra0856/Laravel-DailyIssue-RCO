<?php

namespace App\Handover;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** Klien Bot API Telegram minimal. Pesan error tidak pernah memuat URL (URL berisi token). */
final class Telegram
{
    public function __construct(private string $token) {}

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    /** @return mixed isi field "result" */
    public function call(string $method, array $params = [], int $timeout = 30): mixed
    {
        try {
            $resp = Http::connectTimeout(15)->timeout($timeout)->asJson()
                ->post("https://api.telegram.org/bot{$this->token}/{$method}", $params);
        } catch (ConnectionException) {
            throw new TelegramError("Gagal menghubungi Telegram ({$method}).");
        }
        $data = $resp->json();
        if (! is_array($data) || empty($data['ok'])) {
            $desc = is_array($data) ? (string) ($data['description'] ?? '') : "HTTP {$resp->status()}";
            throw new TelegramError("Telegram menolak {$method}: {$desc}", $desc);
        }

        return $data['result'] ?? null;
    }

    public function sendHtml(int|string $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);
    }
}
