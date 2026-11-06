<?php

namespace App\Services\Daily;

use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/** Menyimpan laporan polisi beserta lampirannya: semua berhasil, atau semua dibatalkan. */
class SimpanLaporan
{
    /**
     * @param  array  $isian  data yang sudah lolos validasi
     * @param  UploadedFile[]  $fileBaru
     * @param  Collection|null  $hapus  lampiran lama yang dibuang
     * @param  DailyIssue|null  $issue  null berarti issue baru
     */
    public function simpan(array $isian, array $fileBaru, ?Collection $hapus = null, ?DailyIssue $issue = null): DailyIssue
    {
        $hapus ??= collect();
        $lapor = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $isian['waktu_lapor']);
        $isian['aplikasi'] = Aplikasi::ejaanResmi($isian['aplikasi']);
        $kolom = [...$isian, 'tanggal' => $lapor->toDateString(), 'waktu_lapor' => $lapor->format('H:i:00')];
        $tersimpan = [];

        try {
            $issue = DB::transaction(function () use ($issue, $kolom, $lapor, $fileBaru, $hapus, &$tersimpan) {
                $issue = $this->simpanIssue($kolom, $issue);
                $hapus->each->delete();
                foreach ($fileBaru as $file) {
                    $tersimpan[] = $this->simpanLampiran($issue, $file, $lapor);
                }

                return $issue;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($tersimpan);  // jangan tinggalkan file tanpa issue

            throw $e;
        }
        // File lama baru dibuang dari disk setelah database berhasil diperbarui.
        Storage::disk('local')->delete($hapus->pluck('path')->all());

        return $issue->fresh()->load('lampiran');
    }

    private function simpanIssue(array $kolom, ?DailyIssue $issue): DailyIssue
    {
        if (! $issue) {
            Aplikasi::catat($kolom['aplikasi'], $kolom['team']);

            return DailyIssue::create([...$kolom, 'status' => 'pending', 'pic' => '']);
        }

        if (mb_strtolower($issue->aplikasi) !== mb_strtolower($kolom['aplikasi'])) {
            Aplikasi::catat($kolom['aplikasi'], $kolom['team']);
        }
        // Status, PIC, dan isian engineer tidak disentuh.
        $issue->update([...$kolom, 'diubah_web_at' => now()]);

        return $issue;
    }

    /** Nama file di disk acak; nama asli hanya disimpan di database. */
    private function simpanLampiran(DailyIssue $issue, UploadedFile $file, CarbonImmutable $lapor): string
    {
        $path = $file->store('lampiran/'.$lapor->format('Y/m'), 'local');
        $issue->lampiran()->create([
            'nama' => mb_substr($file->getClientOriginalName() ?: 'lampiran', 0, 255),
            'path' => $path,
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'ukuran' => $file->getSize(),
        ]);

        return $path;
    }
}
