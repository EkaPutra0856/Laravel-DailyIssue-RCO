<?php

namespace App\Handover;

use App\Handover\Sources\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** Ambil issue sesuai Query dari sumber data. */
final class IssueService implements IssueProvider
{
    private const TAB_RE = '#^\s*issue[\s_]+([A-Za-z]+)\.?[\s_]+(\d{2}|\d{4})\s*$#i';

    public function __construct(private Source $source) {}

    /** Cari tab bulanan: 'Issue Oktober 2026', 'Issue_Jan_2026', 'Issue Ags 25', ' Issue Mei 25', dst. */
    public static function findMonthTab(array $titles, int $year, int $month): ?string
    {
        foreach ($titles as $t) {
            if (! preg_match(self::TAB_RE, $t, $m)) {
                continue;
            }
            $y = Dates::year($m[2]);
            if ($y === $year && Dates::monthNumber($m[1]) === $month) {
                return $t;
            }
        }

        return null;
    }

    /** @return array{0: Issue[], 1: string[]} [issue dalam rentang tanggal, peringatan untuk user] */
    public function collect(Query $query): array
    {
        $warnings = [];
        $months = Dates::monthsBetween($query->start, $query->end);
        $titles = $this->source->listTitles();
        foreach ($months as [$y, $m]) {
            if (self::findMonthTab($titles, $y, $m) === null) {
                // Daftar tab di-cache; tab bulan baru mungkin belum ada di cache, jadi baca ulang sekali.
                $this->source->forgetTitles();
                $titles = $this->source->listTitles();
                break;
            }
        }

        $wanted = $tabMonths = [];
        foreach ($months as [$y, $m]) {
            $tab = self::findMonthTab($titles, $y, $m);
            if ($tab !== null) {
                $wanted[] = $tab;
                $tabMonths[$tab] = [$y, $m];
            } else {
                $warnings[] = "Tab 'Issue ".Dates::NAMA_BULAN[$m - 1]." {$y}' tidak ditemukan";
            }
        }

        $sheets = $this->source->fetch($wanted);
        $detected = [];
        foreach ($sheets as $s) {
            $detected[$s->title] = Rules::detectColumns($s);
        }
        // Tab tanpa header memakai layout tab lain yang header-nya ketemu.
        $fallback = (array_values(array_filter($detected))[0] ?? ColumnMap::default())->withoutHeader();

        $issues = [];
        foreach ($sheets as $sheet) {
            [$year, $month] = $tabMonths[$sheet->title];
            $cols = $detected[$sheet->title] ?? $fallback;
            if ($detected[$sheet->title] === null) {
                Log::warning("Header tidak ditemukan di '{$sheet->title}', pakai layout kolom tab lain/default");
            }
            foreach (self::fixTypoDates(Rules::buildIssues($sheet, $cols), $year, $month) as $i) {
                if ($query->start->lessThanOrEqualTo($i->tanggal) && $i->tanggal->lessThanOrEqualTo($query->end)) {
                    $issues[] = $i;
                }
            }
        }

        return [$issues, $warnings];
    }

    /**
     * Tanggal yang jauh di luar bulan tab dianggap salah ketik.
     * Bulan & hari sama tapi tahun beda -> tahun diganti tahun tab; selain itu pakai tanggal issue sebelumnya.
     *
     * @param  Issue[]  $issues
     * @return Issue[]
     */
    public static function fixTypoDates(array $issues, int $year, int $month, int $slackDays = 7): array
    {
        $lo = Dates::make($year, $month, 1)->subDays($slackDays);
        $hi = Dates::lastDayOfMonth($year, $month)->addDays($slackDays);
        $inside = fn (CarbonImmutable $d) => $lo->lessThanOrEqualTo($d) && $d->lessThanOrEqualTo($hi);
        $prev = null;
        foreach ($issues as $i) {
            if (! $inside($i->tanggal)) {
                $fixed = $i->tanggal->month === $month ? Dates::make($year, $month, $i->tanggal->day) : null;
                $fixed ??= $prev;
                if ($fixed) {
                    Log::info("{$i->sheet} Issue {$i->issueNo}: tanggal {$i->tanggal->toDateString()} dikoreksi ke {$fixed->toDateString()}");
                    $i->tanggal = $fixed;
                }
            }
            if ($inside($i->tanggal)) {
                $prev = $i->tanggal;
            }
        }

        return $issues;
    }
}
