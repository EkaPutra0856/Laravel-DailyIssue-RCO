<?php

namespace Tests\Support;

use App\Handover\RawRow;
use App\Handover\RawSheet;

/** Contoh data yang sama dengan tests/conftest.py di proyek Python (bentuk data seperti kiriman Apps Script). */
final class SampleWorkbook
{
    public const HEADER = ['Tanggal', 'ISSUE', 'Aplikasi', 'Pelapor', 'Media Laporan', 'Penerima Laporan',
        'Pencatat Issue', 'Waktu Lapor', 'Status', 'Pic', 'Waktu Mulai', 'Tanggal Finish',
        'Waktu Selesai', 'Note', 'Aplikasi (IP)', 'Dokumentasi'];

    private const GREEN = [183, 225, 205];       // #b7e1cd
    private const LIGHT_GREEN = [217, 234, 211];  // #d9ead3
    private const YELLOW = [255, 255, 0];

    private static function row(mixed $tgl, string $app, mixed $pelapor, string $status = '', string $pic = '', string $note = ''): array
    {
        return [$tgl, 'deskripsi issue', $app, $pelapor, 'Whatsapp', 'RCO Official', 'Dana',
            '09:00', $status, $pic, '', '', '', $note, '172.18.141.71', ''];
    }

    private static function sheet(string $title, array $rows, array $colors = []): RawSheet
    {
        $out = [];
        foreach ($rows as $i => $values) {
            $n = $i + 1;
            $out[] = new RawRow($n, array_map(fn ($v) => $v === '' ? null : $v, $values),
                $colors[$n] ?? array_fill(0, 16, null));
        }

        return new RawSheet($title, $out);
    }

    /** @return RawSheet[] */
    public static function sheets(): array
    {
        $oktober = self::sheet('Issue Oktober 2026', [
            array_map(fn ($i) => "Column {$i}", range(1, 16)),                              // 1: tabel otomatis
            self::HEADER,                                                                 // 2: header
            self::row('2026-10-01', 'MPAYS', '1 ~Aldi Pratama'),                          // 3 pending Tim 1
            self::row('2026-10-01', 'QRIS', '2 ~Putri', status: 'oncek', pic: 'Hendrik'),  // 4 progress Tim 2
            self::row('2026-10-02', 'JCB', '3 temuan', status: 'Done', pic: 'Elsa'),       // 5 done status
            self::row('2026-10-02', 'BRIMO', 'IT Service Desk'),                          // 6 hijau -> done
            self::row('05/10/2026', 'Swift MT940', 'Tim 1 - Budi', pic: 'select * from x'),  // 7 PIC SQL -> pending
            self::row('2026-10-07', 'EDC', 2.0, status: 'tbd'),                            // 8 progress tanpa PIC
            self::row('2026-10-09', 'BI-FAST', '62 811-1122-993 ~Aldi'),                  // 9 pending ALL TEAM
            self::row('2026-10-10', 'QRIS', '1 ~X', status: '30/12/1899', pic: '-'),       // 10 pending
            self::row('2026-10-11', 'QRIS', '3 ~Y', pic: 'done'),                          // 11 done via pic
            self::row('2026-10-12', 'MPAYS', '1 ~Z'),                                     // 12 light green -> done
            self::row('2026-10-13', 'MPAYS', '2 ~Q', pic: 'Puji'),                         // 13 kuning, progress
            self::row('2026-09-30', 'X', '1 ~W'),                                         // 14 tanggal di luar Oktober
        ], [
            6 => array_fill(0, 16, self::GREEN),
            12 => [self::LIGHT_GREEN, ...array_fill(0, 15, null)],
            13 => array_fill(0, 16, self::YELLOW),
        ]);

        // Tab tanpa header: data langsung dari baris 3
        $september = self::sheet('Issue September 2026', [
            array_fill(0, 16, ''),
            array_fill(0, 16, ''),
            self::row('2026-09-28', 'QRIS', '2 ~A'),                                              // 3 pending Tim 2
            self::row('2026-09-29', 'QRIS', '3 ~B', status: 'on progress', pic: 'Azhari'),         // 4 progress
        ]);

        return [$oktober, $september];
    }

    public static function source(array $gids = []): ArraySource
    {
        return new ArraySource(self::sheets(), $gids);
    }
}
