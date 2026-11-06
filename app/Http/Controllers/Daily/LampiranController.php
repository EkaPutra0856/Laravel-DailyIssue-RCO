<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Models\Lampiran;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class LampiranController extends Controller
{
    /** Gambar ditampilkan langsung; file lain selalu diunduh (tidak dibuka di browser). */
    public function show(Lampiran $lampiran): Response
    {
        if (! Storage::disk('local')->exists($lampiran->path)) {
            return self::json(['error' => 'Lampiran tidak ditemukan'], 404);
        }

        return Storage::disk('local')->response($lampiran->path, $lampiran->nama, [
            'Content-Type' => $lampiran->isGambar() ? $lampiran->mime : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ], $lampiran->isGambar() ? 'inline' : 'attachment');
    }
}
