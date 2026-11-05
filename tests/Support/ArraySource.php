<?php

namespace Tests\Support;

use App\Handover\RawSheet;
use App\Handover\Sources\Source;

/** Sumber data palsu untuk tes (pengganti Apps Script). */
final class ArraySource implements Source
{
    public int $fetches = 0;

    /** @param  RawSheet[]  $sheets  @param  array<string,string>  $gids */
    public function __construct(private array $sheets, private array $gids = []) {}

    public function listTitles(): array
    {
        return array_map(fn (RawSheet $s) => $s->title, $this->sheets);
    }

    public function fetch(array $titles): array
    {
        $this->fetches++;

        return array_values(array_filter($this->sheets, fn (RawSheet $s) => in_array($s->title, $titles, true)));
    }

    public function sheetGids(): array
    {
        return $this->gids;
    }

    public function forgetTitles(): void {}
}
