<?php

namespace App\Http\Controllers;

use App\Handover\Web\HandoverWeb;
use App\Handover\Web\WebError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Rute versi web (sama dengan versi Python, supaya frontend identik):
 *   GET  /                 halaman dashboard
 *   GET  /healthz          cek hidup (tanpa login)
 *   GET  /api/config       judul, tanggal hari ini, daftar tim, tujuan Telegram
 *   GET  /api/issues       ?start=YYYY-MM-DD&end=YYYY-MM-DD[&refresh=1]
 *   POST /api/preview      {start, end, categories, teams, detail, split, keys} -> pesan
 *   POST /api/send         sama dengan preview, lalu kirim ke Telegram
 */
class HandoverController extends Controller
{
    public function __construct(private HandoverWeb $web) {}

    private static function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function rekap(): View
    {
        return view('dashboard');
    }

    public function healthz(): JsonResponse
    {
        return self::json(['ok' => true]);
    }

    public function config(): JsonResponse
    {
        return self::json($this->web->config());
    }

    public function issues(Request $request): JsonResponse
    {
        return self::json($this->web->issuesPayload($request->query('start'), $request->query('end')));
    }

    public function preview(Request $request): JsonResponse
    {
        return self::json(['messages' => $this->web->messages(self::body($request)), 'chat' => $this->web->chatInfo()]);
    }

    public function send(Request $request): JsonResponse
    {
        return self::json($this->web->send(self::body($request)));
    }

    private static function body(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WebError('JSON tidak valid');
        }
        if (! is_array($body) || array_is_list($body) && $body !== []) {
            throw new WebError('JSON harus berupa object');
        }

        return $body;
    }
}
