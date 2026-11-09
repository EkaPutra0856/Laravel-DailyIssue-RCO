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
        'waktu_lapor' => ['waktu lapor', 'jam lapor', 'waktu lapor (jam)', 'waktu_lapor', 'jam'],
        'waktu_mulai' => ['waktu mulai', 'jam mulai', 'waktu_mulai'],
        'tanggal_finish' => ['tanggal finish', 'tgl finish', 'tanggal selesai', 'tgl selesai'],
        'waktu_selesai' => ['waktu selesai', 'jam selesai', 'waktu_selesai'],
        'note' => ['note', 'notes', 'catatan'],
        'aplikasi_ip' => ['aplikasi (ip)', 'aplikasi ip', 'ip aplikasi', 'ip'],
        'dokumentasi' => ['dokumentasi', 'dok', 'dokumen'],
    ];
    // A Tanggal, B ISSUE, C Aplikasi, D TEAM, E Pelapor, F Media, G Penerima, H Pencatat, I Waktu Lapor, J Status, K Pic,
    // L Waktu Mulai, M Tanggal Finish, N Waktu Selesai, O Note, P Aplikasi (IP), Q Dokumentasi
    private const EXTRA_DEFAULT = [
        'issue' => 1, 'media_laporan' => 5, 'penerima_laporan' => 6, 'pencatat_issue' => 7, 'waktu_lapor' => 8,
        'waktu_mulai' => 11, 'tanggal_finish' => 12, 'waktu_selesai' => 13,
        'note' => 14, 'aplikasi_ip' => 15, 'dokumentasi' => 16,
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
     * $lengkapi: untuk baris yang sudah ada, hanya isi Note / Aplikasi (IP) / Dokumentasi yang masih kosong.
     * $sinkron: perbarui baris yang berubah di sheet, kecuali yang sudah diubah lewat web atau sudah dihapus.
     * $dariBaris: jika diisi, lewati baris sebelum nomor baris ini di sheet (tidak memengaruhi baris di atasnya).
     *
     * @return array{baru:int, diperbarui:int, dilewati:int, dilengkapi:int}
     */
    public function importTab(string $tab, int $year, int $month, bool $overwrite = false, bool $lengkapi = false, bool $sinkron = false, ?int $dariBaris = null): array
    {
        $sheet = $this->source->fetch([$tab])[0] ?? new RawSheet($tab, []);
        $cols = Rules::detectColumns($sheet) ?? ColumnMap::default();
        $extra = $this->extraColumns($sheet, $cols);
        $rows = [];
        foreach ($sheet->rows as $row) {
            $rows[$row->rowNumber] = $row;
        }

        $stat = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'dilengkapi' => 0];
        $issues = IssueService::fixTypoDates(Rules::buildIssues($sheet, $cols), $year, $month);
        DB::transaction(function () use ($issues, $rows, $cols, $extra, $tab, $overwrite, $lengkapi, $sinkron, $dariBaris, &$stat) {
            foreach ($issues as $i) {
                // Jika mulai dari baris tertentu, lewati baris di atasnya
                if ($dariBaris !== null && $i->issueNo < $dariBaris) {
                    continue;
                }

                // withTrashed: issue yang sudah dihapus polisi tidak dimunculkan lagi oleh impor ulang
                $existing = DailyIssue::withTrashed()->where('sumber_tab', $tab)->where('sumber_baris', $i->issueNo)->first();
                if ($existing && $sinkron && ! $overwrite) {
                    if (! $existing->trashed() && $existing->diubah_web_at === null) {
                        $existing->fill($this->attributes($i, $rows[$i->issueNo], $cols, $extra));
                        if ($existing->isDirty()) {
                            $existing->save();
                            $stat['diperbarui']++;

                            continue;
                        }
                    }
                    $stat['dilewati']++;

                    continue;
                }
                if ($existing && $lengkapi && ! $overwrite) {
                    $values = $this->attributes($i, $rows[$i->issueNo], $cols, $extra);
                    $isi = [];
                    foreach (['note', 'aplikasi_ip', 'dokumentasi'] as $k) {
                        if (($existing->{$k} ?? '') === '' && ($values[$k] ?? '') !== '') {
                            $isi[$k] = $values[$k];
                        }
                    }
                    if ($isi) {
                        $existing->forceFill($isi)->saveQuietly();
                        $stat['dilengkapi']++;
                    } else {
                        $stat['dilewati']++;
                    }

                    continue;
                }
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
        // Di sheet asli kolom IP (setelah Note) berjudul "Aplikasi" juga: judul "Aplikasi" kedua = Aplikasi (IP).
        $apps = array_keys($header, 'aplikasi', true);
        if ($out['aplikasi_ip'] === null && count($apps) >= 2) {
            $out['aplikasi_ip'] = end($apps);
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

    /** Jam "09:00" / "9.00" / "09:00:00" / serial number Excel / ISO datetime -> "09:00:00"; selain itu null. */
    private static function time(RawRow $row, ?int $idx): ?string
    {
        $v = $idx === null ? null : ($row->values[$idx] ?? null);
        if ($v === null || $v === '' || is_bool($v)) {
            return null;
        }

        // Tangani pecahan serial time Excel (0.0001 - 0.9999...)
        if (is_float($v) && $v > 0 && $v < 1) {
            $totalSec = (int) round($v * 86400);
            $h = intdiv($totalSec, 3600) % 24;
            $m = intdiv($totalSec % 3600, 60);
            $s = $totalSec % 60;
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }

        // Jika angka jam seperti 7.2 atau 19.39
        if (is_numeric($v)) {
            $v = (string) $v;
        }

        if (is_string($v)) {
            $s = trim($v);

            // 1. Format jam biasa di awal: "07:20", "7:20", "7.20", "19.39", "08:37 & 09:49"
            if (preg_match('/^\s*(\d{1,2})[:.](\d{2})(?::(\d{2}))?/', $s, $m)) {
                $h = (int) $m[1];
                $min = (int) $m[2];
                $sec = isset($m[3]) ? (int) $m[3] : 0;
                if ($h < 24 && $min < 60 && $sec < 60) {
                    return sprintf('%02d:%02d:%02d', $h, $min, $sec);
                }
            }

            // 2. Format ISO / datetime: "2026-11-09 07:20:00" atau "1899-12-30T07:20:00.000Z"
            if (preg_match('/(?:T|\s)(\d{1,2})[:.](\d{2})(?::(\d{2}))?/', $s, $m)) {
                $h = (int) $m[1];
                $min = (int) $m[2];
                $sec = isset($m[3]) ? (int) $m[3] : 0;
                if ($h < 24 && $min < 60 && $sec < 60) {
                    return sprintf('%02d:%02d:%02d', $h, $min, $sec);
                }
            }

            // 3. Format jam di bagian manapun dalam string (misal: "pukul 7:20")
            if (preg_match('/(\d{1,2})[:.](\d{2})(?::(\d{2}))?/', $s, $m)) {
                $h = (int) $m[1];
                $min = (int) $m[2];
                $sec = isset($m[3]) ? (int) $m[3] : 0;
                if ($h < 24 && $min < 60 && $sec < 60) {
                    return sprintf('%02d:%02d:%02d', $h, $min, $sec);
                }
            }
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

        $issueText = self::text($row, $extra['issue'], 65000) ?: '(tanpa keterangan)';
        $waktuLapor = self::time($row, $extra['waktu_lapor']);

        // Fallback: Jika waktu lapor di kolom I kosong, tapi teks issue diawali timestamp chat WhatsApp (misal: "[08.14, 9/11/2026]")
        if ($waktuLapor === null && preg_match('/^\[(\d{1,2})[:.](\d{2})[,\s]/', $issueText, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            if ($h < 24 && $min < 60) {
                $waktuLapor = sprintf('%02d:%02d:00', $h, $min);
            }
        }

        return [
            'tanggal' => $i->tanggal->toDateString(),
            'waktu_lapor' => $waktuLapor,
            'issue' => $issueText,
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
            'note' => self::text($row, $extra['note'], 20000) ?: null,
            'aplikasi_ip' => self::text($row, $extra['aplikasi_ip'], 2000) ?: null,
            'dokumentasi' => self::text($row, $extra['dokumentasi'], 20000) ?: null,
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
