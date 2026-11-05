<?php

namespace App\Handover;

/** Aturan bisnis: pemetaan kolom, deteksi warna, tim, sanitasi PIC, klasifikasi issue. */
final class Rules
{
    // (*UCP): \b \d \s \w ikut Unicode, sama seperti regex str Python.
    public const DONE_RE = '#(*UCP)\b(done|close|closed|solved|selesai)\b#iu';
    private const DONE_FULL_RE = '#(*UCP)^(?:\b(done|close|closed|solved|selesai)\b)$#iu';
    private const PIC_JUNK_RE = '#(*UCP)select|update|delete|insert|http|bridrive|^\d{1,3}\.\d|d:\\\\|\.mdf|\.ldf|\bfrom\b|\bwhere\b|--#iu';
    private const SHEETS_TIME_RE = '#(*UCP)\d{1,2}/\d{1,2}/1899#u';
    private const EMPTY_MARKERS = ['', '-', '--', '—', 'tanpa pic', 'n/a', 'na', 'pending'];

    private const HEADER_ALIASES = [
        'tanggal' => ['tanggal', 'tanggal lapor', 'tgl', 'tgl lapor', 'date'],
        'aplikasi' => ['aplikasi', 'app', 'apps', 'aplikasi/modul'],
        'team' => ['team', 'tim'],
        'pelapor' => ['pelapor'],
        'status' => ['status'],
        'pic' => ['pic'],
    ];

    private static function norm(mixed $v): string
    {
        return $v === null ? '' : Py::lower(Py::strip(implode(' ', Py::split(Py::str($v)))));
    }

    /** Cari baris header (berisi 'Status' dan 'Pic') di beberapa baris teratas. */
    public static function detectColumns(RawSheet $sheet, int $scanRows = 6): ?ColumnMap
    {
        foreach (array_slice($sheet->rows, 0, $scanRows) as $row) {
            $found = [];
            foreach ($row->values as $idx => $value) {
                $name = self::norm($value);
                foreach (self::HEADER_ALIASES as $key => $aliases) {
                    if (in_array($name, $aliases, true) && ! array_key_exists($key, $found)) {
                        $found[$key] = $idx;
                    }
                }
            }
            if (isset($found['status'], $found['pic'])) {
                return new ColumnMap(
                    tanggal: $found['tanggal'] ?? 0,
                    aplikasi: $found['aplikasi'] ?? null,
                    team: $found['team'] ?? null,
                    pelapor: $found['pelapor'] ?? null,
                    status: $found['status'],
                    pic: $found['pic'],
                    headerRow: $row->rowNumber,
                );
            }
        }

        return null;
    }

    /** colorsys.rgb_to_hsv Python; @return array{0:float,1:float,2:float} h,s,v di 0..1 */
    private static function hsv(array $rgb): array
    {
        [$r, $g, $b] = array_map(fn ($c) => $c / 255, $rgb);
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        if ($max == $min) {
            return [0.0, 0.0, $max];
        }
        $s = ($max - $min) / $max;
        $rc = ($max - $r) / ($max - $min);
        $gc = ($max - $g) / ($max - $min);
        $bc = ($max - $b) / ($max - $min);
        $h = $r == $max ? $bc - $gc : ($g == $max ? 2.0 + $rc - $bc : 4.0 + $gc - $rc);
        $h /= 6.0;

        return [$h - floor($h), $s, $max];  // modulo 1.0 ala Python (hasil selalu positif)
    }

    private static function hueIn(?array $rgb, float $lo, float $hi, float $minSat): bool
    {
        if ($rgb === null) {
            return false;
        }
        [$h, $s, $v] = self::hsv($rgb);

        return $lo <= $h * 360 && $h * 360 < $hi && $s >= $minSat && $v >= 0.2;
    }

    // Legenda di sheet: hijau = kelar, kuning = lagi dipegang, putih = kerjain,
    // orange = menunggu tindak lanjut eksternal.
    /** Warna latar dianggap hijau kalau hue-nya di rentang hijau dan tidak abu-abu/putih. */
    public static function isGreen(?array $rgb): bool
    {
        return self::hueIn($rgb, 70, 165, 0.08);
    }

    public static function isYellow(?array $rgb): bool
    {
        return self::hueIn($rgb, 45, 70, 0.15);
    }

    public static function isOrange(?array $rgb): bool
    {
        return self::hueIn($rgb, 15, 45, 0.3);
    }

    public static function parseTeam(mixed $teamVal, mixed $pelaporVal): string
    {
        // Kolom TEAM yang terisi (termasuk "All") menentukan; pelapor hanya dipakai kalau TEAM kosong.
        $v = ($teamVal !== null && $teamVal !== '') ? $teamVal : $pelaporVal;
        if ($v === null || $v === '') {
            return 'ALL TEAM';
        }
        if (Py::isNumber($v)) {
            $n = (int) $v;

            return in_array($n, [1, 2, 3], true) && (float) $v == $n ? "Tim {$n}" : 'ALL TEAM';
        }
        $s = self::norm($v);
        if (preg_match('#(*UCP)^(?:tim|team)?\s*([123])(?:\.0+)?(?!\d)#u', $s, $m)
            || preg_match('#(*UCP)\b(?:tim|team)\s*([123])(?!\d)#u', $s, $m)
            || preg_match('#(*UCP)~\s*([123])(?!\d)#u', $s, $m)) {
            return "Tim {$m[1]}";
        }

        return 'ALL TEAM';
    }

    public static function cleanStatus(mixed $v): string
    {
        if (! is_string($v)) {
            return '';  // angka/tanggal/jam di kolom status bukan status
        }
        $s = Py::strip(preg_replace(self::SHEETS_TIME_RE, '', $v));

        return in_array(Py::lower($s), self::EMPTY_MARKERS, true) ? '' : $s;
    }

    /** Nama PIC yang aman ditampilkan; catatan teknis/SQL/IP/path dibuang. */
    public static function sanitizePic(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }
        $s = Py::strip(preg_replace(self::SHEETS_TIME_RE, '', $v));
        if ($s === '' || Py::len($s) > 25 || str_contains($s, "\n") || str_contains($s, "\r")) {
            return '';
        }
        if (preg_match(self::PIC_JUNK_RE, $s) || in_array(Py::lower($s), self::EMPTY_MARKERS, true)
            || preg_match(self::DONE_FULL_RE, $s)) {
            return '';
        }

        return $s;
    }

    /** Teks status untuk ditampilkan sebagai pengganti PIC. */
    public static function safeLabel(string $s): string
    {
        if ($s === '' || Py::len($s) > 25 || str_contains($s, "\n") || preg_match(self::PIC_JUNK_RE, $s)) {
            return '';
        }

        return $s;
    }

    public static function cleanApp(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        $s = Py::strip(Py::str($v));

        return str_contains($s, "\n") || Py::len($s) > 40 ? '' : $s;
    }

    private static function cell(array $values, ?int $idx): mixed
    {
        return $idx === null ? null : ($values[$idx] ?? null);
    }

    /** @return Issue[] */
    public static function buildIssues(RawSheet $sheet, ColumnMap $cols, int $greenScanCols = 17): array
    {
        $issues = [];
        $lastDate = null;
        foreach ($sheet->rows as $row) {
            if ($cols->headerRow && $row->rowNumber <= $cols->headerRow) {
                continue;
            }
            $tanggal = Dates::parseCellDate(self::cell($row->values, $cols->tanggal));
            if ($tanggal === null) {
                // Baris berisi issue tapi tanggalnya lupa diisi: pakai tanggal baris sebelumnya.
                $rawDate = self::cell($row->values, $cols->tanggal);
                $hasIssue = false;
                foreach (array_slice($row->values, 0, $cols->pic + 1) as $i => $v) {
                    if ($i !== $cols->tanggal && ((is_string($v) && Py::strip($v) !== '') || Py::isNumber($v))) {
                        $hasIssue = true;
                        break;
                    }
                }
                if ($lastDate === null || ! $hasIssue
                    || (is_string($rawDate) && Py::lower(Py::strip($rawDate)) === 'tanggal')) {
                    continue;
                }
                $tanggal = $lastDate;
            }
            $lastDate = $tanggal;

            $status = self::cleanStatus(self::cell($row->values, $cols->status));
            $picRaw = self::cell($row->values, $cols->pic);
            $pic = self::sanitizePic($picRaw);
            $colors = array_slice($row->colors, 0, $greenScanCols);
            $green = $orange = $yellow = false;
            foreach ($colors as $c) {
                $green = $green || self::isGreen($c);
                $orange = $orange || self::isOrange($c);
                $yellow = $yellow || self::isYellow($c);
            }

            $doneReason = '';
            if (preg_match(self::DONE_RE, $status)) {
                $doneReason = 'status';
            } elseif (is_string($picRaw) && preg_match(self::DONE_RE, $picRaw)) {
                $doneReason = 'pic';
            } elseif ($green) {
                $doneReason = 'hijau';
            }

            $category = match (true) {
                $doneReason !== '' => Category::DONE,
                $pic === '' && $status === '' && ! ($yellow || $orange) => Category::PENDING,
                default => Category::PROGRESS,
            };

            $issues[] = new Issue(
                sheet: $sheet->title,
                issueNo: $row->rowNumber,
                tanggal: $tanggal,
                team: self::parseTeam(self::cell($row->values, $cols->team), self::cell($row->values, $cols->pelapor)),
                aplikasi: self::cleanApp(self::cell($row->values, $cols->aplikasi)),
                status: self::safeLabel($status),
                pic: $pic,
                category: $category,
                doneReason: $doneReason,
                waitingExternal: $orange && $category === Category::PROGRESS,
            );
        }

        return $issues;
    }
}
