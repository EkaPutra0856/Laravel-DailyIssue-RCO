<?php

namespace Tests\Feature;

use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueProvider;
use App\Handover\Report;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertStringContainsString("<b>Tim 1</b>\n05/11/2026 — Issue {$a['id']} — MPAYS", $msg);
        $this->assertStringContainsString("05/11/2026 — Tim 2 — Issue {$b['id']} — QRIS — Hendrik 🔴 <i>(trouble)</i>", $msg);
        $this->assertStringNotContainsString('BRIVA', $msg);  // done tidak masuk /rekap
        $this->assertStringContainsString('Total Pending: 1 Issue (Putih)', $msg);
        $this->assertStringContainsString('Total On Progress: 1 Issue (Kuning)', $msg);

        // Dashboard rekap ikut membaca database dan link-nya membuka popup issue
        $by = array_column($this->getJson('/api/issues?start=2026-11-05&end=2026-11-05')->json('issues'), null, 'issue_no');
        $this->assertSame('done', $by[$c['id']]['category']);
        $this->assertStringEndsWith("/?issue={$b['id']}", $by[$b['id']]['link']);
    }

    public function test_show_single_issue(): void
    {
        $id = $this->create()['id'];
        $this->getJson("/api/daily/issues/{$id}")->assertOk()->assertJsonPath('pelapor', self::NEW['pelapor']);
        $this->getJson('/api/daily/issues/999')->assertNotFound();
    }
}
