<?php

namespace App\Handover\Sources;

use App\Handover\RawSheet;

/** Sumber data spreadsheet (hanya membaca). */
interface Source
{
    /** @return string[] */
    public function listTitles(): array;

    /**
     * @param  string[]  $titles
     * @return RawSheet[]
     */
    public function fetch(array $titles): array;

    /** gid tiap tab untuk link ke baris; kosong kalau sumber tidak tahu. @return array<string, string> */
    public function sheetGids(): array;

    /** Buang cache daftar tab (dipanggil kalau tab bulan yang dicari belum ada di cache). */
    public function forgetTitles(): void;
}
