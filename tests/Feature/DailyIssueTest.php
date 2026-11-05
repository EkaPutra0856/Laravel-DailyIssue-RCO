<?php

namespace Tests\Feature;

use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueProvider;
use App\Handover\Report;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DailyIssueTest extends TestCase
{
    use RefreshDatabase;

    private const NEW = [
        'issue' => "Mohon dibantu rekans, dana belum masuk\nTID 10787509",
        'aplikasi' => 'Rekon ATM',
        'team' => 'Tim 2',
        'pelapor' => '62 811-1122-993 ~Aldi',
        'media_laporan' => 'WhatsApp Group',
        'penerima_laporan' => 'RCO Official',
        'pencatat_issue' => 'Dana',
        'waktu_lapor' => '2026-11-05T09:15',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['handover.web.password' => '']);
    }

    private function create(array $override = []): array
    {
        return $this->postJson('/api/daily/issues', [...self::NEW, ...$override])->assertCreated()->json();
    }

    public function test_page_renders_in_portal_style(): void
    {
        $this->get('/')->assertOk()->assertSee('PortalRCO')->assertSee('Daily Issue RCO')
            ->assertSee('static/js/daily.js')->assertSee('Catat Issue');
        $this->get('/?embed=1')->assertOk()->assertSee('class="embed"', false);
    }

    public function test_polisi_creates_issue_as_pending(): void
    {
        $row = $this->create(['status' => 'done', 'pic' => 'Hacker']);  // status & PIC dari polisi diabaikan
        $this->assertSame('pending', $row['status']);
        $this->assertSame('', $row['pic']);
        $this->assertSame('2026-11-05', $row['tanggal']);
        $this->assertSame('09:15', $row['waktu_lapor']);
        $this->assertSame(self::NEW['issue'], $row['issue']);
        $this->assertDatabaseHas('daily_issues', ['id' => $row['id'], 'aplikasi' => 'Rekon ATM', 'team' => 'Tim 2']);

        $list = $this->getJson('/api/daily/issues?start=2026-11-05&end=2026-11-05')->assertOk()->json('issues');
        $this->assertSame([$row['id']], array_column($list, 'id'));
        $this->getJson('/api/daily/issues?start=2026-11-06&end=2026-11-06')->assertJsonCount(0, 'issues');
    }

    public function test_create_validation(): void
    {
        $res = $this->postJson('/api/daily/issues', [...self::NEW, 'issue' => '', 'team' => 'Tim 9'])->assertStatus(422);
        $this->assertSame('Issue wajib diisi.', $res->json('error'));
        $this->assertArrayHasKey('team', $res->json('errors'));
        $this->postJson('/api/daily/issues', [...self::NEW, 'waktu_lapor' => 'kemarin'])->assertStatus(422);
        $this->post('/api/daily/issues', self::NEW)->assertStatus(415);  // wajib JSON
        $this->assertDatabaseCount('daily_issues', 0);
    }

    public function test_engineer_submit_requires_pic_and_working_status(): void
    {
        $id = $this->create()['id'];
        $url = "/api/daily/issues/{$id}/tindak-lanjut";

        $this->putJson($url, ['status' => 'onprogress', 'pic' => ''])->assertStatus(422)->assertJsonPath('error', 'PIC wajib diisi.');
        $this->putJson($url, ['status' => 'pending', 'pic' => 'Hendrik'])->assertStatus(422)
            ->assertJsonPath('error', 'Status harus On Progress, Trouble, atau Done.');
        $this->assertSame('pending', DailyIssue::find($id)->status);

        $row = $this->putJson($url, ['status' => 'onprogress', 'pic' => 'Hendrik', 'waktu_mulai' => '09:30'])->assertOk()->json();
        $this->assertSame(['onprogress', 'Hendrik', '09:30'], [$row['status'], $row['pic'], $row['waktu_mulai']]);

        $row = $this->putJson($url, ['status' => 'done', 'pic' => 'Hendrik', 'waktu_mulai' => '09:30',
            'tanggal_finish' => '2026-11-05', 'waktu_selesai' => '10:05'])->assertOk()->json();
        $this->assertSame(['done', '2026-11-05', '10:05'], [$row['status'], $row['tanggal_finish'], $row['waktu_selesai']]);

        $this->putJson('/api/daily/issues/999/tindak-lanjut', ['status' => 'done', 'pic' => 'X'])->assertNotFound();
    }

    public function test_aplikasi_team_recommendation_and_consistency(): void
    {
        $this->create();
        $this->getJson('/api/daily/aplikasi')->assertOk()->assertJsonPath('0.nama', 'Rekon ATM')->assertJsonPath('0.team', 'Tim 2');

        // Ejaan lain disamakan dengan nama terdaftar; team pemetaan tidak berubah walau issue ini memilih team lain.
        $row = $this->create(['aplikasi' => '  rekon   atm ', 'team' => 'Tim 3']);
        $this->assertSame('Rekon ATM', $row['aplikasi']);
        $this->assertSame('Tim 3', $row['team']);
        $app = Aplikasi::findByNama('REKON ATM');
        $this->assertSame(['Tim 2', 2], [$app->team, $app->jumlah_issue]);
        $this->assertSame(1, Aplikasi::count());
    }

    public function test_rekap_reads_database(): void
    {
        $a = $this->create(['team' => 'Tim 1', 'aplikasi' => 'MPAYS']);
        $b = $this->create(['team' => 'Tim 2', 'aplikasi' => 'QRIS']);
        $c = $this->create(['team' => 'Tim 3', 'aplikasi' => 'BRIVA']);
        $this->putJson("/api/daily/issues/{$b['id']}/tindak-lanjut", ['status' => 'trouble', 'pic' => 'Hendrik'])->assertOk();
        $this->putJson("/api/daily/issues/{$c['id']}/tindak-lanjut", ['status' => 'done', 'pic' => 'Elsa'])->assertOk();

        $q = Commands::parse('/rekapdetail', Dates::make(2026, 11, 5));
        [$issues] = app(IssueProvider::class)->collect($q);
        $msg = Report::buildMessages($issues, $q, 'Handover Polisi – Team RCO')[0];
        $this->assertStringContainsString("<b>Tim 1</b>\n05/11/2026 — Issue {$a['nomor']} — MPAYS", $msg);
        $this->assertStringContainsString("05/11/2026 — Tim 2 — Issue {$b['nomor']} — QRIS — Hendrik 🔴 <i>(trouble)</i>", $msg);
        $this->assertStringNotContainsString('BRIVA', $msg);  // done tidak masuk /rekap
        $this->assertStringContainsString('Total Pending: 1 Issue (Putih)', $msg);
        $this->assertStringContainsString('Total On Progress: 1 Issue (Kuning)', $msg);

        // Popup "Kirim ke Telegram" polisi: hanya issue yang tampil di tabel (kunci bulan#nomor)
        $msg = $this->postJson('/api/preview', ['start' => '2026-11-05', 'end' => '2026-11-05',
            'categories' => ['progress', 'done'], 'keys' => ["{$b['bulan']}#{$b['nomor']}"]])->assertOk()->json('messages.0');
        $this->assertStringContainsString("Issue {$b['nomor']} — Hendrik", $msg);
        $this->assertStringNotContainsString('Elsa', $msg);
    }

    public function test_polisi_uploads_images_and_files(): void
    {
        Storage::fake('local');
        $res = $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => [
            UploadedFile::fake()->image('screenshot.png', 400, 300),
            UploadedFile::fake()->create('rekon.xlsx', 120, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ]], ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'])->assertCreated();

        $att = $res->json('lampiran');
        $this->assertSame(['screenshot.png', 'rekon.xlsx'], array_column($att, 'nama'));
        $this->assertSame([true, false], array_column($att, 'gambar'));
        $this->assertSame('pending', $res->json('status'));
        $this->assertCount(2, Storage::disk('local')->allFiles('lampiran'));

        // Daftar issue ikut membawa lampiran (untuk penanda di tabel & popup engineer)
        $list = $this->getJson('/api/daily/issues?start=2026-11-05&end=2026-11-05')->json('issues.0.lampiran');
        $this->assertCount(2, $list);

        // Gambar tampil langsung, file lain selalu diunduh
        $img = $this->get("/api/daily/lampiran/{$att[0]['id']}")->assertOk();
        $this->assertStringStartsWith('inline', $img->headers->get('Content-Disposition'));
        $this->assertSame('image/png', $img->headers->get('Content-Type'));
        $file = $this->get("/api/daily/lampiran/{$att[1]['id']}")->assertOk();
        $this->assertStringStartsWith('attachment', $file->headers->get('Content-Disposition'));
        $this->assertSame('application/octet-stream', $file->headers->get('Content-Type'));
        $this->get('/api/daily/lampiran/999')->assertNotFound();
    }

    public function test_upload_rules(): void
    {
        Storage::fake('local');
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'];
        $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => [UploadedFile::fake()->create('virus.exe', 10)]], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'Jenis file lampiran tidak didukung (gambar, PDF, Word/Excel/PowerPoint, TXT/CSV, ZIP).');
        $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => [UploadedFile::fake()->image('besar.jpg')->size(11000)]], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'Ukuran tiap lampiran maksimal 10 MB.');
        $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.png"), range(1, 11))], $headers)
            ->assertStatus(422)->assertJsonPath('error', 'Maksimal 10 lampiran per issue.');
        // Form lintas situs (tanpa header X-Requested-With) ditolak
        $this->post('/api/daily/issues', [...self::NEW, 'lampiran' => [UploadedFile::fake()->image('a.png')]])->assertStatus(415);
        $this->assertDatabaseCount('daily_issues', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_engineer_fills_note_ip_dokumentasi_not_sent_to_telegram(): void
    {
        $row = $this->create();
        $data = $this->putJson("/api/daily/issues/{$row['id']}/tindak-lanjut", [
            'status' => 'onprogress', 'pic' => 'Hendrik',
            'note' => "select * from rekon where tid='10787509'", 'aplikasi_ip' => '172.18.141.71',
            'dokumentasi' => 'https://drive.google.com/x',
        ])->assertOk()->json();
        $this->assertSame(["select * from rekon where tid='10787509'", '172.18.141.71', 'https://drive.google.com/x'],
            [$data['note'], $data['aplikasi_ip'], $data['dokumentasi']]);
        $this->assertSame('172.18.141.71', $this->getJson('/api/daily/issues?start=2026-11-05&end=2026-11-05')->json('issues.0.aplikasi_ip'));

        // Catatan teknis / IP tidak pernah ikut ke pesan Telegram
        $msg = $this->postJson('/api/preview', ['start' => '2026-11-05', 'end' => '2026-11-05', 'categories' => ['progress'], 'detail' => true])
            ->json('messages.0');
        $this->assertStringContainsString('Hendrik', $msg);
        $this->assertStringNotContainsString('172.18', $msg);
        $this->assertStringNotContainsString('select', $msg);
        $this->assertStringNotContainsString('drive.google', $msg);

        $this->putJson("/api/daily/issues/{$row['id']}/tindak-lanjut", ['status' => 'done', 'pic' => 'X', 'aplikasi_ip' => str_repeat('1', 2100)])
            ->assertStatus(422)->assertJsonPath('error', 'Aplikasi (IP) maksimal 2000 karakter.');
    }

    public function test_show_single_issue(): void
    {
        $id = $this->create()['id'];
        $this->getJson("/api/daily/issues/{$id}")->assertOk()->assertJsonPath('pelapor', self::NEW['pelapor']);
        $this->getJson('/api/daily/issues/999')->assertNotFound();
    }
}
