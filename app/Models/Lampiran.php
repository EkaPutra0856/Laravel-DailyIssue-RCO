<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gambar / file lampiran sebuah issue. */
class Lampiran extends Model
{
    protected $table = 'lampiran';

    protected $fillable = ['daily_issue_id', 'nama', 'path', 'mime', 'ukuran'];

    public const MAKS_PER_ISSUE = 10;
    public const MAKS_KB = 10240;  // 10 MB per file
    public const EKSTENSI = 'jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,log,zip,rar,7z';

    // Gambar yang aman ditampilkan langsung di browser (SVG tidak, karena bisa berisi script).
    public const GAMBAR = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(DailyIssue::class, 'daily_issue_id');
    }

    public function isGambar(): bool
    {
        return in_array($this->mime, self::GAMBAR, true);
    }

    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'gambar' => $this->isGambar(),
            'url' => url("/api/daily/lampiran/{$this->id}"),
        ];
    }
}
