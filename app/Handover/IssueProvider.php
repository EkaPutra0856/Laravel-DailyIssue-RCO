<?php

namespace App\Handover;

/** Sumber issue untuk rekap (dashboard rekap, bot, CLI). */
interface IssueProvider
{
    /** @return array{0: Issue[], 1: string[]} [issue dalam rentang tanggal, peringatan untuk user] */
    public function collect(Query $query): array;
}
