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
        $this->assertSame(['baru' => 12, 'diperbarui' => 0, 'dilewati' => 0], $stat);

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
        $this->assertSame(['baru' => 0, 'diperbarui' => 0, 'dilewati' => 12], $importer->importTab('Issue Oktober 2026', 2026, 10));
        $this->assertSame('Puji', DailyIssue::where('sumber_baris', 3)->value('pic'));
        $this->assertSame(['baru' => 0, 'diperbarui' => 12, 'dilewati' => 0], $importer->importTab('Issue Oktober 2026', 2026, 10, overwrite: true));
        $this->assertSame('', DailyIssue::where('sumber_baris', 3)->value('pic'));
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
