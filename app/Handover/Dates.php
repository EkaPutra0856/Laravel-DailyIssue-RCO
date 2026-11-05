<?php

namespace App\Handover;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Utilitas tanggal berbahasa Indonesia. Tanggal disimpan sebagai CarbonImmutable jam 00:00 UTC. */
final class Dates
{
    public const NAMA_BULAN = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    // Nama lengkap, singkatan Indonesia, dan singkatan Inggris -> nomor bulan (1-12)
    private const BULAN_ALIAS = [
        'januari' => 1, 'jan' => 1, 'january' => 1,
        'februari' => 2, 'feb' => 2, 'pebruari' => 2, 'february' => 2,
        'maret' => 3, 'mar' => 3, 'march' => 3,
        'april' => 4, 'apr' => 4,
        'mei' => 5, 'may' => 5,
        'juni' => 6, 'jun' => 6, 'june' => 6,
        'juli' => 7, 'jul' => 7, 'july' => 7,
        'agustus' => 8, 'agu' => 8, 'agt' => 8, 'ags' => 8, 'agst' => 8, 'agus' => 8, 'aug' => 8, 'august' => 8,
        'september' => 9, 'sep' => 9, 'sept' => 9,
        'oktober' => 10, 'okt' => 10, 'oct' => 10, 'october' => 10,
        'november' => 11, 'nov' => 11, 'nop' => 11,
        'desember' => 12, 'des' => 12, 'dec' => 12, 'december' => 12,
    ];

    /** Tanggal valid, atau null (pengganti date(y, m, d) + ValueError). */
    public static function make(int $y, int $m, int $d): ?CarbonImmutable
    {
        if ($y < 1 || ! checkdate($m, $d, $y)) {
            return null;
        }

        return CarbonImmutable::create($y, $m, $d, 0, 0, 0, 'UTC');
    }

    public static function fromIso(string $s): ?CarbonImmutable
    {
        if (! preg_match('#^(\d{4})-(\d{2})-(\d{2})$#', $s, $m)) {
            return null;
        }

        return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    public static function monthNumber(string $name): ?int
    {
        return self::BULAN_ALIAS[rtrim(Py::lower(Py::strip($name)), '.')] ?? null;
    }

    public static function fmt(CarbonImmutable $d): string
    {
        return $d->format('d/m/Y');
    }

    public static function fmtRange(CarbonImmutable $start, CarbonImmutable $end): string
    {
        return $start->equalTo($end) ? self::fmt($start) : self::fmt($start).' - '.self::fmt($end);
    }

    public static function lastDayOfMonth(int $y, int $m): CarbonImmutable
    {
        return CarbonImmutable::create($y, $m, 1, 0, 0, 0, 'UTC')->endOfMonth()->startOfDay();
    }

    /** @return array<int, array{0:int,1:int}> daftar [tahun, bulan] dari bulan $start sampai bulan $end, inklusif */
    public static function monthsBetween(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $out = [];
        [$y, $m] = [$start->year, $start->month];
        while ([$y, $m] <= [$end->year, $end->month]) {
            $out[] = [$y, $m];
            [$y, $m] = $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
        }

        return $out;
    }

    /** Serial tanggal Google Sheets / Excel dihitung dari 30 Desember 1899. */
    public static function fromSerial(float $serial): ?CarbonImmutable
    {
        // Rentang wajar saja: serial 1 (1899) sampai ~tahun 2200.
        if (! (1 <= $serial && $serial < 110000)) {
            return null;
        }

        return CarbonImmutable::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays((int) $serial);
    }

    /** Parse isi sel kolom Tanggal: tanggal, serial angka, atau teks DD/MM/YYYY / '1 Oktober 2026'. */
    public static function parseCellDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '' || is_bool($value)) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return (int) $value->format('Y') > 1900
                ? self::make((int) $value->format('Y'), (int) $value->format('n'), (int) $value->format('j'))
                : null;
        }
        if (Py::isNumber($value)) {
            return self::fromSerial((float) $value);
        }

        $s = Py::strip((string) $value);
        if ($s === '' || str_contains($s, '1899')) {
            return null;
        }
        // Buang nama hari di depan: "Rabu, 1 Oktober 2026"
        $s = preg_replace('#(*UCP)^[A-Za-z]+,\s*#u', '', $s);

        if (preg_match('#(*UCP)^(\d{1,2})\s+([A-Za-z]+)\.?\s+(\d{2,4})#u', $s, $m)) {
            $mon = self::monthNumber($m[2]);
            if ($mon) {
                return self::make(self::year($m[3]), $mon, (int) $m[1]);
            }
        }
        if (preg_match('#(*UCP)^(\d{1,4})[/\-.](\d{1,2})[/\-.](\d{1,4})#u', $s, $m)) {
            [, $a, $b, $c] = $m;
            if (strlen($a) === 4) {  // YYYY-MM-DD
                return self::make((int) $a, (int) $b, (int) $c);
            }

            return self::make(self::year($c), (int) $b, (int) $a);  // DD/MM/YYYY (format Indonesia)
        }

        return null;
    }

    public static function year(string $s): int
    {
        $y = (int) $s;

        return $y < 100 ? $y + 2000 : $y;
    }
}
