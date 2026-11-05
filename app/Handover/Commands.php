<?php

namespace App\Handover;

use Carbon\CarbonImmutable;

/** Parse teks perintah Telegram menjadi Query rekap. */
final class Commands
{
    /** Perintah -> [kategori default, detail, split] */
    public static function all(): array
    {
        return [
            'rekap' => [[Category::PENDING, Category::PROGRESS], false, false],
            'rekapdetail' => [[Category::PENDING, Category::PROGRESS], true, false],
            'pending' => [[Category::PENDING], false, false],
            'belum' => [[Category::PENDING], false, false],
            'progress' => [[Category::PROGRESS], false, false],
            'ongoing' => [[Category::PROGRESS], false, false],
            'onprogress' => [[Category::PROGRESS], false, false],
            'done' => [[Category::DONE], false, false],
            'selesai' => [[Category::DONE], false, false],
            'doneprogress' => [[Category::DONE, Category::PROGRESS], false, true],
        ];
    }

    private const CATEGORY_WORDS = [
        'pending' => Category::PENDING, 'belum' => Category::PENDING, 'putih' => Category::PENDING,
        'progress' => Category::PROGRESS, 'ongoing' => Category::PROGRESS, 'kuning' => Category::PROGRESS,
        'done' => Category::DONE, 'selesai' => Category::DONE, 'hijau' => Category::DONE,
    ];

    private const RANGE_SEP = '#(*UCP)\s*(?::|–|—|\bs/d\b|\bsd\b|\bsampai\b|\bhingga\b|\bto\b|\s-\s)\s*#iu';
    private const DAY_SPAN = '#(*UCP)^(\d{1,2})\s*[-–]\s*(\d{1,2})\s+([A-Za-z]+)\.?(?:\s+(\d{4}))?$#u';

    public static function parse(?string $text, CarbonImmutable $today): Query
    {
        $text = Py::strip($text ?? '');
        if (! preg_match('#(*UCP)^/(\w+)(?:@\w+)?\s*(.*)$#su', $text, $m)) {
            throw new CommandError("Perintah harus diawali '/'. Ketik /help untuk panduan.");
        }
        [$cmd, $rest] = [Py::lower($m[1]), $m[2]];
        $commands = self::all();
        if (! isset($commands[$cmd])) {
            throw new CommandError("Perintah /{$cmd} tidak dikenal. Ketik /help untuk panduan.");
        }
        [$categories, $detail, $split] = $commands[$cmd];

        // Ambil kata kunci modifier, sisanya adalah periode.
        $periodWords = $picked = [];
        foreach (Py::split($rest) as $w) {
            $lw = trim(Py::lower($w), ',');
            if ($lw === 'detail') {
                $detail = true;
            } elseif ($lw === 'pisah' || $lw === 'split') {
                $split = true;
            } elseif (isset(self::CATEGORY_WORDS[$lw])) {
                if (! in_array(self::CATEGORY_WORDS[$lw], $picked, true)) {
                    $picked[] = self::CATEGORY_WORDS[$lw];
                }
            } else {
                $periodWords[] = $w;
            }
        }
        if ($picked) {
            $categories = $picked;
            if (count($picked) > 1) {
                $split = $split || in_array(Category::DONE, $picked, true);
            }
        }

        [$start, $end] = self::parsePeriod(implode(' ', $periodWords), $today);

        return new Query($start, $end, $categories, $detail, $split);
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    public static function parsePeriod(string $text, CarbonImmutable $today): array
    {
        $s = Py::strip(implode(' ', Py::split($text)));
        $low = Py::lower($s);
        if (in_array($low, ['', 'hari ini', 'today'], true)) {
            return [$today, $today];
        }
        if (in_array($low, ['kemarin', 'yesterday'], true)) {
            $d = $today->subDay();

            return [$d, $d];
        }
        if ($low === 'minggu ini') {
            return [$today->subDays($today->dayOfWeekIso - 1), $today];
        }
        if ($low === 'bulan ini') {
            return [Dates::make($today->year, $today->month, 1), Dates::lastDayOfMonth($today->year, $today->month)];
        }
        if ($low === 'bulan lalu') {
            $first = Dates::make($today->year, $today->month, 1)->subDay();

            return [Dates::make($first->year, $first->month, 1), $first];
        }

        // "5-7 Oktober 2026"
        if (preg_match(self::DAY_SPAN, $s, $m) && ($mon = Dates::monthNumber($m[3]))) {
            $y = ($m[4] ?? '') !== '' ? (int) $m[4] : $today->year;

            return self::ordered(self::mk($y, $mon, (int) $m[1]), self::mk($y, $mon, (int) $m[2]));
        }

        $parts = array_values(array_filter(preg_split(self::RANGE_SEP, $s), fn ($p) => Py::strip($p) !== ''));
        if (count($parts) === 1) {
            $p = self::parsePoint($parts[0]);
            $y = $p['y'] ?: $today->year;
            if ($p['m'] === null) {
                throw new CommandError(self::badPeriod($s));
            }
            if ($p['d'] === null) {  // bulan saja
                return [Dates::make($y, $p['m'], 1), Dates::lastDayOfMonth($y, $p['m'])];
            }
            $d = self::mk($y, $p['m'], $p['d']);

            return [$d, $d];
        }
        if (count($parts) !== 2) {
            throw new CommandError(self::badPeriod($s));
        }

        [$a, $b] = [self::parsePoint($parts[0]), self::parsePoint($parts[1])];
        if ($b['m'] === null) {
            throw new CommandError(self::badPeriod($s));
        }
        $by = $b['y'] ?: $today->year;
        $am = $a['m'] ?: $b['m'];
        $ay = $a['y'] ?: ($am > $b['m'] ? $by - 1 : $by);
        $start = self::mk($ay, $am, $a['d'] ?: 1);
        $end = self::mk($by, $b['m'], $b['d'] ?: Dates::lastDayOfMonth($by, $b['m'])->day);

        return self::ordered($start, $end);
    }

    /** @return array{d:?int,m:?int,y:?int} */
    private static function parsePoint(string $s): array
    {
        $s = rtrim(Py::strip($s), '.');
        $pats = [
            ['#(*UCP)^(\d{4})-(\d{1,2})-(\d{1,2})$#u', ['y', 'm', 'd']],
            ['#(*UCP)^(\d{1,2})[/.\-](\d{1,2})(?:[/.\-](\d{2,4}))?$#u', ['d', 'm', 'y']],
            ['#(*UCP)^(\d{1,2})\s+([A-Za-z]+)\.?(?:\s+(\d{2,4}))?$#u', ['d', 'M', 'y']],
            ['#(*UCP)^([A-Za-z]+)\.?(?:\s+(\d{4}))?$#u', ['M', 'y']],
            ['#(*UCP)^(\d{1,2})$#u', ['d']],
        ];
        foreach ($pats as [$pat, $keys]) {
            if (! preg_match($pat, $s, $m)) {
                continue;
            }
            $out = ['d' => null, 'm' => null, 'y' => null];
            foreach ($keys as $i => $k) {
                $v = $m[$i + 1] ?? '';
                if ($v === '') {
                    continue;
                }
                if ($k === 'M') {
                    $mon = Dates::monthNumber($v);
                    if ($mon === null) {
                        continue 2;  // nama bulan tidak dikenal: coba pola berikutnya
                    }
                    $out['m'] = $mon;
                } else {
                    $n = (int) $v;
                    $out[$k] = ($k === 'y' && $n < 100) ? $n + 2000 : $n;
                }
            }

            return $out;
        }
        throw new CommandError(self::badPeriod($s));
    }

    private static function mk(int $y, int $m, int $d): CarbonImmutable
    {
        return Dates::make($y, $m, $d)
            ?? throw new CommandError(sprintf('Tanggal %02d/%02d/%d tidak valid.', $d, $m, $y));
    }

    /** @return array{0:CarbonImmutable,1:CarbonImmutable} */
    private static function ordered(CarbonImmutable $a, CarbonImmutable $b): array
    {
        return $a->lessThanOrEqualTo($b) ? [$a, $b] : [$b, $a];
    }

    private static function badPeriod(string $s): string
    {
        return "Periode '{$s}' tidak dikenali. Contoh: /rekap Oktober 2026, /rekap 13/10/2026, /rekap 28 September 2026 : 2 Oktober 2026";
    }
}
