<?php

namespace App\Http\Requests\Daily;

use App\Models\DailyIssue;
use App\Models\Lampiran;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class TindakLanjutRequest extends DailyRequest
{
    public function rules(): array
    {
        $sisa = $this->issueLama()?->lampiran->count() - $this->lampiranDihapus()->count();

        return [
            'lampiran' => 'array|max:'.(Lampiran::MAKS_PER_ISSUE - $sisa),
            'lampiran.*' => 'file|max:'.Lampiran::MAKS_KB.'|mimes:'.Lampiran::EKSTENSI,
            'status' => ['required', Rule::in(DailyIssue::ENGINEER_STATUSES)],
            'pic' => 'required|string|max:100',
            'waktu_mulai' => 'nullable|date_format:H:i',
            'tanggal_finish' => 'nullable|date_format:Y-m-d',
            'waktu_selesai' => 'nullable|date_format:H:i',
            'note' => 'nullable|string|max:20000',
            'aplikasi_ip' => 'nullable|string|max:2000',
            'dokumentasi' => 'nullable|string|max:20000',
        ];
    }

    public function validationData(): array
    {
        return [...$this->except('lampiran'), 'lampiran' => $this->lampiranBaru()];
    }

    /** @return UploadedFile[] */
    public function lampiranBaru(): array
    {
        return array_values(array_filter((array) $this->file('lampiran', [])));
    }

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
