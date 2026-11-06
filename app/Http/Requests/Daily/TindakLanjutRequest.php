<?php

namespace App\Http\Requests\Daily;

use App\Models\DailyIssue;
use Illuminate\Validation\Rule;

class TindakLanjutRequest extends DailyRequest
{
    public function rules(): array
    {
        return [
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
}
