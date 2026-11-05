<?php

namespace Tests\Feature;

use App\Handover\Import\SheetImporter;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_maps_columns_and_status(): void
    {
        $importer = new SheetImporter(SampleWorkbook::source());
        $this->assertSame(['Issue Oktober 2026' => [2026, 10]], $importer->tabsFor([[2026, 10], [2026, 11]]));

        $stat = $importer->importTab('Issue Oktober 2026', 2026, 10);
        $this->assertSame(['baru' => 12, 'diperbarui' => 0, 'dilewati' => 0, 'dilengkapi' => 0], $stat);

        $by = DailyIssue::all()->keyBy('sumber_baris');
        $this->assertSame('pending', $by[3]->status);
        $this->assertSame('Tim 1', $by[3]->team);
        $this->assertSame('MPAYS', $by[3]->aplikasi);
        $this->assertSame('deskripsi issue', $by[3]->issue);
        $this->assertSame('Whatsapp', $by[3]->media_laporan);
        $this->assertSame('RCO Official', $by[3]->penerima_laporan);
        $this->assertSame('Dana', $by[3]->pencatat_issue);
        $this->assertSame('09:00:00', $by[3]->waktu_lapor);
        $this->assertSame(['onprogress', 'Hendrik'], [$by[4]->status, $by[4]->pic]);
        $this->assertSame('done', $by[5]->status);   // status "Done"
        $this->assertSame('done', $by[6]->status);   // baris hijau
        $this->assertSame('', $by[7]->pic);          // PIC berisi SQL tidak ikut
        $this->assertSame('2026-09-30', $by[14]->tanggal->toDateString());

        // Impor ulang tidak menggandakan & tidak menimpa perubahan dari web
        $by[3]->update(['status' => 'onprogress', 'pic' => 'Puji']);
        $this->assertSame(['baru' => 0, 'diperbarui' => 0, 'dilewati' => 12, 'dilengkapi' => 0], $importer->importTab('Issue Oktober 2026', 2026, 10));
        $this->assertSame('Puji', DailyIssue::where('sumber_baris', 3)->value('pic'));
        $this->assertSame(['baru' => 0, 'diperbarui' => 12, 'dilewati' => 0, 'dilengkapi' => 0], $importer->importTab('Issue Oktober 2026', 2026, 10, overwrite: true));
        $this->assertSame('', DailyIssue::where('sumber_baris', 3)->value('pic'));
    }

    public function test_import_reads_ip_and_lengkapi_fills_only_empty(): void
    {
        $importer = new SheetImporter(SampleWorkbook::source());
        $importer->importTab('Issue Oktober 2026', 2026, 10);
        $this->assertSame('172.18.141.71', DailyIssue::where('sumber_baris', 3)->value('aplikasi_ip'));

        // Data lama (sebelum kolom ini ada) kosong; --lengkapi mengisi yang kosong saja, isian engineer tidak disentuh
        DailyIssue::query()->update(['aplikasi_ip' => null]);
        DailyIssue::where('sumber_baris', 4)->update(['aplikasi_ip' => '10.0.0.9', 'pic' => 'Puji']);
        $stat = $importer->importTab('Issue Oktober 2026', 2026, 10, lengkapi: true);
        $this->assertSame(11, $stat['dilengkapi']);
        $this->assertSame('172.18.141.71', DailyIssue::where('sumber_baris', 3)->value('aplikasi_ip'));
        $this->assertSame(['10.0.0.9', 'Puji'], [DailyIssue::where('sumber_baris', 4)->value('aplikasi_ip'), DailyIssue::where('sumber_baris', 4)->value('pic')]);
    }

    public function test_second_aplikasi_header_is_ip_column(): void
    {
        // Layout sheet asli: kolom setelah Note berjudul "Aplikasi" lagi (isinya IP)
        $header = [null, 'ISSUE', 'Aplikasi', 'TEAM', 'Pelapor', 'Media Laporan', 'Penerima Laporan', 'Pencatat Issue',
            'Waktu Lapor', 'Status', 'Pic', 'Waktu Mulai', 'Tanggal Finish', 'Waktu Selesai', 'Note', 'Aplikasi', 'Dokumentasi'];
        $row = ['2026-11-01', 'transaksi belum masuk', 'BIFAST', 2, 'Briapi', 'Whatsapp', 'RCO Official', 'Latasya', null,
            'Done', 'Latasya', null, '2026-11-01', null, '-- query cek', "172.18.36.9\ndb : Reconcile", 'SELECT * FROM x'];
        $sheet = new \App\Handover\RawSheet('Issue November 2026', [
            new \App\Handover\RawRow(1, ['isa', ...array_fill(0, 16, null)], array_fill(0, 17, null)),
            new \App\Handover\RawRow(2, $header, array_fill(0, 17, null)),
            new \App\Handover\RawRow(3, $row, array_fill(0, 17, null)),
        ]);
        (new SheetImporter(new \Tests\Support\ArraySource([$sheet])))->importTab('Issue November 2026', 2026, 11);
        $d = DailyIssue::first();
        $this->assertSame(['BIFAST', 'Tim 2', 'done'], [$d->aplikasi, $d->team, $d->status]);
        $this->assertSame(['-- query cek', "172.18.36.9\ndb : Reconcile", 'SELECT * FROM x'], [$d->note, $d->aplikasi_ip, $d->dokumentasi]);
    }

    public function test_rebuild_aplikasi_uses_most_frequent_team(): void
    {
        $importer = new SheetImporter(SampleWorkbook::source());
        $importer->importTab('Issue Oktober 2026', 2026, 10);
        Aplikasi::create(['nama' => 'QRIS', 'team' => 'Tim 1', 'jumlah_issue' => 0]);  // sudah terdaftar: team tidak diubah

        $this->assertGreaterThan(0, SheetImporter::rebuildAplikasi());
        $this->assertSame('Tim 1', Aplikasi::findByNama('mpays')->team);   // MPAYS: Tim 1 x2, Tim 2 x1
        $this->assertSame('Tim 1', Aplikasi::findByNama('qris')->team);
        $this->assertSame(3, Aplikasi::findByNama('qris')->jumlah_issue);
    }
}
