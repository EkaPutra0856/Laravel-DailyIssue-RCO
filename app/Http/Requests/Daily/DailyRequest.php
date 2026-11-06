<?php

namespace App\Http\Requests\Daily;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Dasar validasi Daily Issue: pesan bahasa Indonesia dan format error 422 {"error": ..., "errors": {...}}. */
abstract class DailyRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'max' => ':attribute maksimal :max karakter.',
            'in' => ':attribute tidak valid.',
            'date_format' => 'Format :attribute tidak valid.',
            'date' => 'Format :attribute tidak valid.',
            'status.in' => 'Status harus On Progress, Trouble, atau Done.',
            'lampiran.max' => 'Maksimal 10 lampiran per issue.',
            'lampiran.*.max' => 'Ukuran tiap lampiran maksimal 10 MB.',
            'lampiran.*.mimes' => 'Jenis file lampiran tidak didukung (gambar, PDF, Word/Excel/PowerPoint, TXT/CSV, ZIP).',
            'lampiran.*.uploaded' => 'Lampiran gagal diunggah (mungkin terlalu besar).',
            'lampiran.*.file' => 'Lampiran gagal diunggah.',
        ];
    }

    public function attributes(): array
    {
        return [
            'issue' => 'Issue', 'aplikasi' => 'Aplikasi', 'team' => 'Team', 'pelapor' => 'Pelapor',
            'media_laporan' => 'Media laporan', 'penerima_laporan' => 'Penerima laporan', 'pencatat_issue' => 'Pencatat issue',
            'waktu_lapor' => 'Waktu lapor', 'status' => 'Status', 'pic' => 'PIC', 'waktu_mulai' => 'Waktu mulai',
            'tanggal_finish' => 'Tanggal finish', 'waktu_selesai' => 'Waktu selesai',
            'note' => 'Note', 'aplikasi_ip' => 'Aplikasi (IP)', 'dokumentasi' => 'Dokumentasi',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(Controller::json([
            'error' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
