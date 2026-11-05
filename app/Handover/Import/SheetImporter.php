<?php

namespace App\Handover\Import;

use App\Handover\Category;
use App\Handover\ColumnMap;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\Py;
use App\Handover\RawRow;
use App\Handover\RawSheet;
use App\Handover\Rules;
use App\Handover\Sources\Source;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Illuminate\Support\Facades\DB;

/**
 * Salin issue dari tab "Issue <Bulan> <Tahun>" sheet asli ke database. Sheet asli hanya dibaca.
 * Tanggal, team, PIC, dan status memakai aturan yang sama dengan rekap (Rules + IssueService::fixTypoDates).
 */
final class SheetImporter
{
    // Kolom tambahan yang tidak dibaca aturan rekap; dicari dari baris header, kalau tidak ada pakai layout default.
    private const EXTRA_ALIASES = [
        'issue' => ['issue'],
        'media_laporan' => ['media laporan', 'media'],
        'penerima_laporan' => ['penerima laporan', 'penerima'],
        'pencatat_issue' => ['pencatat issue', 'pencatat'],
        'waktu_lapor' => ['waktu lapor', 'jam lapor'],
        'waktu_mulai' => ['waktu mulai', 'jam mulai'],
        'tanggal_finish' => ['tanggal finish', 'tgl finish', 'tanggal selesai'],
        'waktu_selesai' => ['waktu selesai', 'jam selesai'],
    ];
    // A Tanggal, B ISSUE, C Aplikasi, D Pelapor, E Media, F Penerima, G Pencatat, H Waktu Lapor, I Status, J Pic,
    // K Waktu Mulai, L Tanggal Finish, M Waktu Selesai
    private const EXTRA_DEFAULT = [
        'issue' => 1, 'media_laporan' => 4, 'penerima_laporan' => 5, 'pencatat_issue' => 6, 'waktu_lapor' => 7,
        'waktu_mulai' => 10, 'tanggal_finish' => 11, 'waktu_selesai' => 12,
    ];

    public function __construct(private Source $source) {}

    /** @return string[] nama tab Issue untuk bulan-bulan ini (yang tidak ada dilewati) */
    public function tabsFor(array $months): array
    {
        $titles = $this->source->listTitles();
        $out = [];
        foreach ($months as [$y, $m]) {
            if ($tab = IssueService::findMonthTab($titles, $y, $m)) {
                $out[$tab] = [$y, $m];
            }
        }

        return $out;
    }

    /**
     * Impor satu tab. Baris yang sudah pernah diimpor dilewati, kecuali $overwrite (data dari web ikut tertimpa).
     *
     * @return array{baru:int, diperbarui:int, dilewati:int}
     */
    public function importTab(string $tab, int $year, int $month, bool $overwrite = false): array
    {
        $sheet = $this->source->fetch([$tab])[0] ?? new RawSheet($tab, []);
        $cols = Rules::detectColumns($sheet) ?? ColumnMap::default();
        $extra = $this->extraColumns($sheet, $cols);
        $rows = [];
        foreach ($sheet->rows as $row) {
            $rows[$row->rowNumber] = $row;
        }

        $stat = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0];
        $issues = IssueService::fixTypoDates(Rules::buildIssues($sheet, $cols), $year, $month);
        DB::transaction(function () use ($issues, $rows, $cols, $extra, $tab, $overwrite, &$stat) {
            foreach ($issues as $i) {
                // withTrashed: issue yang sudah dihapus polisi tidak dimunculkan lagi oleh impor ulang
                $existing = DailyIssue::withTrashed()->where('sumber_tab', $tab)->where('sumber_baris', $i->issueNo)->first();
                if ($existing && (! $overwrite || $existing->trashed())) {
                    $stat['dilewati']++;

                    continue;
                }
                $values = $this->attributes($i, $rows[$i->issueNo], $cols, $extra) + ['sumber_tab' => $tab, 'sumber_baris' => $i->issueNo];
                if ($existing) {
                    $existing->update($values);
                    $stat['diperbarui']++;
                } else {
                    DailyIssue::create($values);
                    $stat['baru']++;
                }
            }
        });

        return $stat;
    }

    private static function norm(mixed $v): string
    {
        return $v === null ? '' : Py::lower(implode(' ', Py::split(Py::str($v))));
    }

    /** @return array<string, int|null> indeks kolom tambahan */
    private function extraColumns(RawSheet $sheet, ColumnMap $cols): array
    {
        $header = null;
        foreach ($sheet->rows as $row) {
            if ($row->rowNumber === $cols->headerRow) {
                $header = array_map(self::norm(...), $row->values);
                break;
            }
        }
        if ($header === null) {
            return self::EXTRA_DEFAULT;
        }
        $out = [];
        foreach (self::EXTRA_ALIASES as $key => $aliases) {
            $idx = null;
            foreach ($header as $i => $name) {
                if (in_array($name, $aliases, true)) {
                    $idx = $i;
                    break;
                }
            }
            $out[$key] = $idx;
        }

        return $out;
    }

    private static function text(RawRow $row, ?int $idx, int $max): string
    {
        $v = $idx === null ? null : ($row->values[$idx] ?? null);
        if ($v === null || is_bool($v)) {
            return '';
        }

        return mb_substr(Py::strip(Py::str($v)), 0, $max);
    }

    /** Jam "09:00" / "9.00" / "09:00:00" -> "09:00:00"; selain itu null. */
    private static function time(RawRow $row, ?int $idx): ?string
    {
        $v = $idx === null ? null : ($row->values[$idx] ?? null);
        if (is_string($v) && preg_match('/^\s*(\d{1,2})[:.](\d{2})(?::(\d{2}))?/', $v, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d:00', $m[1], $m[2]);
        }

        return null;
    }

    private function attributes(\App\Handover\Issue $i, RawRow $row, ColumnMap $cols, array $extra): array
    {
        $status = match (true) {
            $i->category === Category::DONE => 'done',
            $i->category === Category::PROGRESS && $i->waitingExternal => 'trouble',
            $i->category === Category::PROGRESS => 'onprogress',
            default => 'pending',
        };
        $aplikasi = self::text($row, $cols->aplikasi, 150);

        return [
            'tanggal' => $i->tanggal->toDateString(),
            'waktu_lapor' => self::time($row, $extra['waktu_lapor']),
            'issue' => self::text($row, $extra['issue'], 65000) ?: '(tanpa keterangan)',
            'aplikasi' => str_contains($aplikasi, "\n") ? $i->aplikasi : $aplikasi,
            'team' => $i->team,
            'pelapor' => self::text($row, $cols->pelapor, 150),
            'media_laporan' => self::text($row, $extra['media_laporan'], 100),
            'penerima_laporan' => self::text($row, $extra['penerima_laporan'], 150),
            'pencatat_issue' => self::text($row, $extra['pencatat_issue'], 100),
            'status' => $status,
            'pic' => mb_substr($i->pic, 0, 100),
            'waktu_mulai' => self::time($row, $extra['waktu_mulai']),
            'tanggal_finish' => Dates::parseCellDate($extra['tanggal_finish'] === null ? null : ($row->values[$extra['tanggal_finish']] ?? null))?->toDateString(),
            'waktu_selesai' => self::time($row, $extra['waktu_selesai']),
        ];
    }

    /**
     * Isi tabel aplikasi dari issue yang ada: tiap aplikasi -> team yang paling sering menanganinya.
     * Aplikasi yang sudah terdaftar tidak diganti team-nya (supaya konsisten), hanya jumlah issue diperbarui.
     *
     * @return int jumlah aplikasi baru
     */
    public static function rebuildAplikasi(): int
    {
        $stats = [];
        foreach (DailyIssue::query()->select('aplikasi', 'team')->where('aplikasi', '!=', '')->cursor() as $d) {
            $nama = trim(preg_replace('/\s+/u', ' ', $d->aplikasi));
            $key = mb_strtolower($nama);
            $stats[$key]['nama'][$nama] = ($stats[$key]['nama'][$nama] ?? 0) + 1;
            $stats[$key]['team'][$d->team] = ($stats[$key]['team'][$d->team] ?? 0) + 1;
        }
        $baru = 0;
        foreach ($stats as $s) {
            arsort($s['nama']);
            arsort($s['team']);
            $nama = array_key_first($s['nama']);
            $jumlah = array_sum($s['team']);
            if ($app = Aplikasi::findByNama($nama)) {
                $app->update(['jumlah_issue' => $jumlah]);
            } else {
                Aplikasi::create(['nama' => $nama, 'team' => array_key_first($s['team']), 'jumlah_issue' => $jumlah]);
                $baru++;
            }
        }

        return $baru;
    }
}
