<?php

namespace App\Http\Requests\Daily;

use App\Handover\Teams;
use App\Models\DailyIssue;
use App\Models\Lampiran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Isian laporan polisi, saat membuat issue baru maupun mengeditnya. */
class SimpanIssueRequest extends DailyRequest
{
    public function rules(): array
    {
        $sisa = $this->issueLama()?->lampiran->count() - $this->lampiranDihapus()->count();

        return [
            'lampiran' => 'array|max:'.(Lampiran::MAKS_PER_ISSUE - $sisa),
            'lampiran.*' => 'file|max:'.Lampiran::MAKS_KB.'|mimes:'.Lampiran::EKSTENSI,
            'issue' => 'required|string|max:5000',
            'aplikasi' => 'required|string|max:150',
            'team' => ['required', Rule::in(Teams::ALL)],
            'pelapor' => 'required|string|max:150',
            'media_laporan' => 'required|string|max:100',
            'penerima_laporan' => 'required|string|max:150',
            'pencatat_issue' => 'required|string|max:100',
            'waktu_lapor' => 'required|date_format:Y-m-d\TH:i',
        ];
    }

    public function validationData(): array
    {
        return [...$this->except('lampiran'), 'lampiran' => $this->lampiranBaru()];
    }

    /** Isian laporan yang sudah lolos validasi, tanpa file lampiran. */
    public function isian(): array
    {
        return $this->safe()->except('lampiran');
    }

    /** @return UploadedFile[] */
    public function lampiranBaru(): array
    {
        return array_values(array_filter((array) $this->file('lampiran', [])));
    }

    /** Lampiran lama yang diminta dihapus (hanya milik issue ini). */
    public function lampiranDihapus(): Collection
    {
        $id = array_map('intval', (array) $this->input('hapus_lampiran', []));

        return $this->issueLama()?->lampiran->whereIn('id', $id) ?? collect();
    }

    private function issueLama(): ?DailyIssue
    {
        return $this->route('issue');
    }
}
