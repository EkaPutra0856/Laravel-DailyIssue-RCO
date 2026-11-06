<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\TindakLanjutRequest;
use App\Models\DailyIssue;
use Illuminate\Http\JsonResponse;

/** Engineer menindaklanjuti issue. Wajib PIC dan status On Progress / Trouble / Done. */
class TindakLanjutController extends Controller
{
    public function update(TindakLanjutRequest $request, DailyIssue $issue): JsonResponse
    {
        $data = $request->validated();
        $issue->update([
            'status' => $data['status'],
            'follow_up' => $data['status'] === 'done' ? false : $issue->follow_up,
            'pic' => $data['pic'],
            'waktu_mulai' => ($data['waktu_mulai'] ?? null) ?: null,
            'tanggal_finish' => ($data['tanggal_finish'] ?? null) ?: null,
            'waktu_selesai' => ($data['waktu_selesai'] ?? null) ?: null,
            'note' => ($data['note'] ?? null) ?: null,
            'aplikasi_ip' => ($data['aplikasi_ip'] ?? null) ?: null,
            'dokumentasi' => ($data['dokumentasi'] ?? null) ?: null,
            'diubah_web_at' => now(),
        ]);

        return self::json($issue->fresh()->load('lampiran')->toRow());
    }
}
