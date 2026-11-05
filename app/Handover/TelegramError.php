<?php

namespace App\Handover;

use RuntimeException;

/** Panggilan Bot API gagal. $description diisi kalau Telegram menolak; null kalau tidak bisa terhubung. */
final class TelegramError extends RuntimeException
{
    public function __construct(string $message, public ?string $description = null)
    {
        parent::__construct($message);
    }
}
