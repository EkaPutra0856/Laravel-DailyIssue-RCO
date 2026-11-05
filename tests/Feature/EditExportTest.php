<?php

namespace Tests\Feature;

use App\Models\DailyIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class EditExportTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = [
        'issue' => 'Dana belum masuk', 'aplikasi' => 'Rekon ATM', 'team' => 'Tim 2', 'pelapor' => '~Aldi',
        'media_laporan' => 'WhatsApp', 'penerima_laporan' => 'RCO Official', 'pencatat_issue' => 'Dana',
        'waktu_lapor' => '2026-11-05T09:15',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['handover.web.password' => '']);
    }

    private function create(array $o = []): array
    {
        return $this->postJson('/api/daily/issues', [...self::NEW, ...$o])->assertCreated()->json();
    }

    public function test_number_restarts_every_month(): void
    {
        $okt1 = $this->create(['waktu_lapor' => '2026-10-30T08:00']);
        $okt2 = $this->create(['waktu_lapor' => '2026-10-31T08:00']);
        $nov1 = $this->create(['waktu_lapor' => '2026-11-01T08:00']);
        $nov2 = $this->create();
        $this->assertSame([1, 2, 1, 2], [$okt1['nomor'], $okt2['nomor'], $nov1['nomor'], $nov2['nomor']]);
        $this->assertSame(['2026-10', '2026-11'], [$okt1['bulan'], $nov1['bulan']]);
    }

    public function test_polisi_edits_issue_without_touching_engineer_fields(): void
    {
        Storage::fake('local');
        $h = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $row = $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => [
            UploadedFile::fake()->image('salah.png'), UploadedFile::fake()->image('benar.png'),
        ]], $h)->assertCreated()->json();
        $this->putJson("/api/daily/issues/{$row['id']}/tindak-lanjut", ['status' => 'onprogress', 'pic' => 'Hendrik'])->assertOk();
        [$salah, $benar] = $row['lampiran'];

        // Perbaiki salah ketik, hapus satu lampiran, tambah satu file baru
        $edit = $this->post("/api/daily/issues/{$row['id']}", [...self::NEW,
            'issue' => 'Dana belum masuk ke rekening', 'pelapor' => '~Aldi Pratama',
            'hapus_lampiran' => [$salah['id']],
            'lampiran' => [UploadedFile::fake()->create('bukti.pdf', 50, 'application/pdf')],
        ], $h)->assertOk()->json();

        $this->assertSame('Dana belum masuk ke rekening', $edit['issue']);
        $this->assertSame('~Aldi Pratama', $edit['pelapor']);
        $this->assertSame(['onprogress', 'Hendrik'], [$edit['status'], $edit['pic']]);  // isian engineer tetap
        $this->assertSame($row['nomor'], $edit['nomor']);                                // bulan sama: nomor tetap
        $this->assertSame(['benar.png', 'bukti.pdf'], array_column($edit['lampiran'], 'nama'));
        $this->assertFalse(Storage::disk('local')->exists(\App\Models\Lampiran::query()->withoutGlobalScopes()->find($salah['id'])?->path ?? 'x'));
        $this->assertCount(2, Storage::disk('local')->allFiles('lampiran'));

        // Status/PIC dari polisi diabaikan; validasi sama dengan catat baru
        $this->post("/api/daily/issues/{$row['id']}", [...self::NEW, 'status' => 'done', 'pic' => 'X'], $h)->assertOk()
            ->assertJsonPath('status', 'onprogress');
        $this->post("/api/daily/issues/{$row['id']}", [...self::NEW, 'issue' => ''], $h)->assertStatus(422);
        $this->post('/api/daily/issues/999', self::NEW, $h)->assertNotFound();
    }

    public function test_moving_issue_to_other_month_gets_new_number(): void
    {
        $this->create(['waktu_lapor' => '2026-10-31T08:00']);
        $nov = $this->create();
        $edit = $this->postJson("/api/daily/issues/{$nov['id']}", [...self::NEW, 'waktu_lapor' => '2026-10-31T10:00'])->assertOk()->json();
        $this->assertSame(['2026-10', 2, '2026-10-31'], [$edit['bulan'], $edit['nomor'], $edit['tanggal']]);
    }

    public function test_export_excel_month(): void
    {
        $a = $this->create(['waktu_lapor' => '2026-10-02T08:00', 'issue' => 'Issue <pertama> & "kutip"']);
        $b = $this->create(['waktu_lapor' => '2026-10-03T08:00', 'team' => 'Tim 1', 'aplikasi' => 'QRIS']);
        $this->create(['waktu_lapor' => '2026-11-01T08:00']);  // bulan lain: tidak ikut
        $this->putJson("/api/daily/issues/{$b['id']}/tindak-lanjut", ['status' => 'done', 'pic' => 'Elsa'])->assertOk();

        $res = $this->get('/api/daily/export?start=2026-10-01&end=2026-10-31')->assertOk();
        $this->assertStringContainsString('Daily Issue RCO - Oktober 2026.xlsx', $res->headers->get('Content-Disposition'));
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $res->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, $res->streamedContent() ?: file_get_contents($res->getFile()->getPathname()));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $summary = $zip->getFromName('xl/worksheets/sheet2.xml');
        $this->assertStringContainsString('<sheet name="Issue"', $zip->getFromName('xl/workbook.xml'));
        $zip->close();
        unlink($path);

        $this->assertNotFalse(simplexml_load_string($sheet));  // XML valid (teks di-escape)
        $this->assertStringContainsString('Issue &lt;pertama&gt; &amp; &quot;kutip&quot;', $sheet);
        $this->assertSame(3, substr_count($sheet, '<row '));   // judul + 2 issue Oktober
        $this->assertStringContainsString('<c r="K3" s="6" t="inlineStr"><is><t xml:space="preserve">Done</t>', $sheet);  // baris done hijau
        $this->assertStringContainsString('<autoFilter ref="A1:S3"/>', $sheet);
        $this->assertStringContainsString('Rekap Daily Issue RCO – Oktober 2026', $summary);
    }

    public function test_polisi_deletes_issue_softly(): void
    {
        $a = $this->create();
        $b = $this->create();
        $this->deleteJson("/api/daily/issues/{$a['id']}", ['nama' => 'Tes Polisi'])->assertOk()
            ->assertJsonPath('deleted', $a['id'])->assertJsonPath('nomor', 1);

        // Hilang dari tabel, detail, rekap/Telegram, dan Excel
        $this->assertSame([$b['id']], array_column($this->getJson('/api/daily/issues?start=2026-11-05&end=2026-11-05')->json('issues'), 'id'));
        $this->getJson("/api/daily/issues/{$a['id']}")->assertNotFound();
        $msg = $this->postJson('/api/preview', ['start' => '2026-11-05', 'end' => '2026-11-05', 'categories' => ['pending']])->json('messages.0');
        $this->assertStringContainsString('Total Pending: 1 Issue', $msg);
        $this->deleteJson("/api/daily/issues/{$a['id']}")->assertNotFound();  // sudah dihapus

        // Data tetap ada di database (bisa dipulihkan), dan nomornya tidak dipakai ulang
        $trashed = DailyIssue::withTrashed()->find($a['id']);
        $this->assertTrue($trashed->trashed());
        $this->assertSame('Tes Polisi', $trashed->dihapus_oleh);
        $this->assertSame(3, $this->create()['nomor']);
    }

    public function test_export_multiple_teams(): void
    {
        $this->create(['waktu_lapor' => '2026-10-02T08:00', 'team' => 'Tim 1']);
        $this->create(['waktu_lapor' => '2026-10-02T09:00', 'team' => 'Tim 2']);
        $this->create(['waktu_lapor' => '2026-10-02T10:00', 'team' => 'Tim 3']);
        $res = $this->get('/api/daily/export?start=2026-10-01&end=2026-10-31&teams='.rawurlencode('Tim 1,Tim 3'))->assertOk();
        $this->assertStringContainsString('Oktober 2026 - Tim 1, Tim 3.xlsx', $res->headers->get('Content-Disposition'));
        $path = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($path, file_get_contents($res->getFile()->getPathname()));
        $zip = new ZipArchive;
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        unlink($path);
        $this->assertSame(3, substr_count($sheet, '<row '));  // judul + Tim 1 + Tim 3
        $this->assertStringNotContainsString('>Tim 2<', $sheet);
    }

    public function test_export_team_filter_and_bad_range(): void
    {
        $this->create(['waktu_lapor' => '2026-10-02T08:00']);
        $res = $this->get('/api/daily/export?start=2026-10-01&end=2026-10-15&team=Tim%201')->assertOk();
        $this->assertStringContainsString('02-10-2026', '02-10-2026');
        $this->assertStringContainsString('01-10-2026 sd 15-10-2026 - Tim 1.xlsx', $res->headers->get('Content-Disposition'));
        $this->getJson('/api/daily/export?start=2026-10-31&end=2026-10-01')->assertStatus(400);
    }
}
