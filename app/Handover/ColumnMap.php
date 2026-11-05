<?php

namespace App\Handover;

final class ColumnMap
{
    public function __construct(
        public int $tanggal,
        public ?int $aplikasi,
        public ?int $team,       // null kalau tim menyatu di kolom pelapor
        public ?int $pelapor,
        public int $status,
        public int $pic,
        public ?int $headerRow = null,  // nomor baris header fisik, null kalau tidak ketemu
    ) {}

    /** Layout sheet sumber kalau baris header tidak ditemukan: A Tanggal, C Aplikasi, D Pelapor, I Status, J Pic. */
    public static function default(): self
    {
        return new self(tanggal: 0, aplikasi: 2, team: null, pelapor: 3, status: 8, pic: 9);
    }

    public function withoutHeader(): self
    {
        return new self($this->tanggal, $this->aplikasi, $this->team, $this->pelapor, $this->status, $this->pic, null);
    }
}
