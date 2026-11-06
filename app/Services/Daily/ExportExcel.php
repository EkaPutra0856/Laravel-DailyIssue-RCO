<?php

namespace App\Services\Daily;

use App\Handover\Dates;
use App\Handover\Export\XlsxWriter;
use App\Handover\Teams;
use App\Models\DailyIssue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Rekap periode sebagai file Excel: sheet Issue (berwarna sesuai status) dan Ringkasan per tim. */
class ExportExcel
{
    private const JUDUL_ISSUE = ['No', 'Tanggal', 'Issue', 'Aplikasi', 'Team', 'Pelapor', 'Media Laporan', 'Penerima Laporan',
        'Pencatat Issue', 'Waktu Lapor', 'Status', 'PIC', 'Waktu Mulai', 'Tanggal Finish', 'Waktu Selesai',
        'Note', 'Aplikasi (IP)', 'Dokumentasi', 'Lampiran'];
    private const LEBAR_ISSUE = [6, 11, 60, 18, 10, 24, 16, 22, 16, 11, 12, 16, 11, 13, 12, 40, 20, 30, 28];
    private const LEBAR_RINGKASAN = [16, 11, 13, 11, 11, 11];

    /**
     * @param  string[]  $teams  kosong berarti semua tim
     * @return array{path: string, nama: string}
     */
    public function buat(CarbonImmutable $awal, CarbonImmutable $akhir, array $teams = []): array
    {
        $issues = DailyIssue::periode($awal, $akhir)
            ->when($teams, fn ($q) => $q->whereIn('team', $teams))
            ->orderBy('tanggal')->orderBy('nomor')
            ->with('lampiran')
            ->get();
        $periode = $this->namaPeriode($awal, $akhir);

        [$baris, $gaya] = $this->sheetIssue($issues);
        [$ringkasan, $sel] = $this->sheetRingkasan($issues, $periode);

        $path = tempnam(sys_get_temp_dir(), 'rekap');
        (new XlsxWriter)
            ->addSheet('Issue', $baris, self::LEBAR_ISSUE, $gaya)
            ->addSheet('Ringkasan', $ringkasan, self::LEBAR_RINGKASAN, array_fill(4, count(Teams::ALL), XlsxWriter::BOLD), 3, $sel)
            ->save($path);

        $tim = $teams ? ' - '.implode(', ', $teams) : '';

        return ['path' => $path, 'nama' => "Daily Issue RCO - {$periode}{$tim}.xlsx"];
    }

    /** "November 2026" kalau tepat satu bulan penuh, selain itu "01-11-2026 sd 05-11-2026". */
    private function namaPeriode(CarbonImmutable $awal, CarbonImmutable $akhir): string
    {
        $bulanPenuh = $awal->day === 1 && $akhir->equalTo($awal->endOfMonth()->startOfDay());

        return $bulanPenuh
            ? Dates::NAMA_BULAN[$awal->month - 1].' '.$awal->year
            : $awal->format('d-m-Y').' sd '.$akhir->format('d-m-Y');
    }

    /** @return array{0: array, 1: array} baris dan warna tiap baris */
    private function sheetIssue(Collection $issues): array
    {
        $tanggal = fn ($d) => $d ? $d->format('d/m/Y') : '';
        $jam = fn ($t) => (string) DailyIssue::hhmm($t);

        $baris = [self::JUDUL_ISSUE];
        $gaya = [];
        foreach ($issues->values() as $i => $d) {
            $baris[] = [
                $d->nomor, $tanggal($d->tanggal), $d->issue, $d->aplikasi, $d->team, $d->pelapor, $d->media_laporan,
                $d->penerima_laporan, $d->pencatat_issue, $jam($d->waktu_lapor), DailyIssue::STATUS_LABEL[$d->status] ?? $d->status,
                $d->pic, $jam($d->waktu_mulai), $tanggal($d->tanggal_finish), $jam($d->waktu_selesai),
                (string) $d->note, (string) $d->aplikasi_ip, (string) $d->dokumentasi,
                $d->lampiran->pluck('nama')->implode(', '),
            ];
            $gaya[$i + 1] = XlsxWriter::FILLS[$d->status] ?? XlsxWriter::WRAP;
        }

        return [$baris, $gaya];
    }

    /** @return array{0: array, 1: array} baris dan gaya sel: jumlah per tim x status */
    private function sheetRingkasan(Collection $issues, string $periode): array
    {
        $diunduh = CarbonImmutable::now(config('handover.timezone'))->format('d/m/Y H:i');
        $baris = [["Rekap Daily Issue RCO – {$periode}"], ["Diunduh {$diunduh}"], [],
            ['Team', 'Pending', 'On Progress', 'Trouble', 'Done', 'Total']];

        $total = array_fill_keys(DailyIssue::STATUSES, 0);
        foreach (Teams::ALL as $tim) {
            $milikTim = $issues->where('team', $tim);
            $jumlah = [];
            foreach (DailyIssue::STATUSES as $status) {
                $jumlah[] = $n = $milikTim->where('status', $status)->count();
                $total[$status] += $n;
            }
            $baris[] = [$tim, ...$jumlah, $milikTim->count()];
        }
        $baris[] = ['Total', ...array_values($total), $issues->count()];

        $terakhir = count($baris) - 1;
        $sel = ['0:0' => XlsxWriter::TITLE, '1:0' => XlsxWriter::NORMAL];
        foreach (range(0, 5) as $kolom) {
            $sel["{$terakhir}:{$kolom}"] = XlsxWriter::HEADER;
        }

        return [$baris, $sel];
    }
}
