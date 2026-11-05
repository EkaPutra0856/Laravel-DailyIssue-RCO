<?php

namespace App\Handover;

use Carbon\CarbonImmutable;

final class Issue
{
    public function __construct(
        public string $sheet,
        public int $issueNo,
        public CarbonImmutable $tanggal,
        public string $team,
        public string $aplikasi,
        public string $status,
        public string $pic,
        public Category $category,
        public string $doneReason = '',        // "status", "pic", atau "hijau"
        public bool $waitingExternal = false,  // baris orange: menunggu tindak lanjut eksternal
    ) {}

    /** Nama PIC untuk ditampilkan; kalau PIC kosong, nama timnya. */
    public function label(): string
    {
        return $this->pic !== '' ? $this->pic : $this->team;
    }

    public function key(): string
    {
        return "{$this->sheet}#{$this->issueNo}";
    }
}
