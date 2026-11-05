<?php

namespace App\Handover;

use Carbon\CarbonImmutable;

final class Query
{
    /**
     * @param  Category[]  $categories
     * @param  string[]|null  $teams  null = semua tim (versi web bisa memilih tim)
     */
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
        public array $categories,
        public bool $detail = false,
        public bool $split = false,  // tiap kategori dikirim di bubble chat terpisah
        public ?array $teams = null,
    ) {}
}
