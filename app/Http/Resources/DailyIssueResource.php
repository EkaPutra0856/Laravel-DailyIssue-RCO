<?php

namespace App\Http\Resources;

use App\Models\DailyIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transformasi data Daily Issue ke format respons JSON frontend.
 *
 * @mixin \App\Models\DailyIssue
 */
class DailyIssueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nomor' => $this->nomor,
            'bulan' => $this->bulan,
            'tanggal' => $this->tanggal?->toDateString(),
            'waktu_lapor' => DailyIssue::hhmm($this->waktu_lapor),
            'issue' => $this->issue,
            'aplikasi' => $this->aplikasi,
            'team' => $this->team,
            'pelapor' => $this->pelapor,
            'media_laporan' => $this->media_laporan,
            'penerima_laporan' => $this->penerima_laporan,
            'pencatat_issue' => $this->pencatat_issue,
            'status' => $this->status,
            'follow_up' => (bool) $this->follow_up,
            'pic' => $this->pic,
            'waktu_mulai' => DailyIssue::hhmm($this->waktu_mulai),
            'tanggal_finish' => $this->tanggal_finish?->toDateString(),
            'waktu_selesai' => DailyIssue::hhmm($this->waktu_selesai),
            'note' => (string) $this->note,
            'aplikasi_ip' => (string) $this->aplikasi_ip,
            'dokumentasi' => (string) $this->dokumentasi,
            'sumber' => $this->sumber_tab ? "{$this->sumber_tab} baris {$this->sumber_baris}" : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lampiran' => LampiranResource::collection($this->lampiran)->resolve(),
        ];
    }
}
