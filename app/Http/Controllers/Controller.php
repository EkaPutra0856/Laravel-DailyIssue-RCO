<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

abstract class Controller
{
    /** Jawaban JSON: huruf Indonesia tidak di-escape, dan browser tidak menyimpan cache. */
    public static function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }
}
