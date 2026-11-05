<?php

namespace App\Handover;

/** Satu baris mentah dari sumber. */
final class RawRow
{
    /**
     * @param  int  $rowNumber  nomor baris fisik di sheet (1-based)
     * @param  array<int, mixed>  $values  nilai sel berurutan dari kolom A
     * @param  array<int, array{0:int,1:int,2:int}|null>  $colors  warna latar tiap sel (RGB 0-255), sejajar dengan $values
     */
    public function __construct(
        public int $rowNumber,
        public array $values,
        public array $colors,
    ) {}
}
