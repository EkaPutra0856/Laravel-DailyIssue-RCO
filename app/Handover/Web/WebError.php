<?php

namespace App\Handover\Web;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/** Error yang pesannya aman ditampilkan ke user; dirender sebagai {"error": "..."}. */
final class WebError extends Exception
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        if ($this->status >= 500) {
            Log::warning("{$this->status}: {$this->getMessage()}");
        }

        return response()->json(['error' => $this->getMessage()], $this->status, [], JSON_UNESCAPED_UNICODE);
    }

    public function report(): bool
    {
        return true;  // sudah dicatat di render(); jangan isi log dengan stack trace
    }
}
