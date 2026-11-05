<?php

namespace App\Handover;

use App\Models\DailyIssue;

/** Issue dari database (tabel daily_issues). Urutan: tanggal, lalu id (setara urutan baris di sheet). */
final class DbIssueProvider implements IssueProvider
{
    public function collect(Query $query): array
    {
        $issues = DailyIssue::query()
            ->whereBetween('tanggal', [$query->start->toDateString(), $query->end->toDateString()])
            ->orderBy('tanggal')->orderBy('id')
            ->get()
            ->map(fn (DailyIssue $d) => $d->toIssue())
            ->all();

        return [$issues, []];
    }
}
