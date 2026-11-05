<?php

namespace App\Handover;

final class RawSheet
{
    /** @param  RawRow[]  $rows */
    public function __construct(
        public string $title,
        public array $rows,
    ) {}
}
