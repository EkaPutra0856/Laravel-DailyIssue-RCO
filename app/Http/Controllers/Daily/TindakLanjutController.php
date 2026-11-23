<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\TindakLanjutRequest;
use App\Models\DailyIssue;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Engineer menindaklanjuti issue. Wajib PIC dan status On Progress / Trouble / Done. */
class TindakLanjutController extends Controller
{
    public function update(TindakLanjutRequest $request, DailyIssue $issue): JsonResponse
    {
        $data = $request->validated();
        $hapus = $request->lampiranDihapus();
        $tersimpan = [];

        try {
            DB::transaction(function () use ($issue, $data, $hapus, $request, &$tersimpan) {
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

                $hapus->each->delete();
                foreach ($request->lampiranBaru() as $file) {
                    $path = $file->store('lampiran/'.now()->format('Y/m'), 'local');
                    $tersimpan[] = $path;
                    $issue->lampiran()->create([
                        'nama' => mb_substr($file->getClientOriginalName() ?: 'lampiran', 0, 255),
                        'path' => $path,
                        'mime' => $file->getMimeType() ?: 'application/octet-stream',
                        'ukuran' => $file->getSize(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($tersimpan);
            throw $e;
        }

        Storage::disk('local')->delete($hapus->pluck('path')->all());

        return self::json($issue->fresh()->load('lampiran')->toRow());
    }
}
