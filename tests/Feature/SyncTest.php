<?php

namespace Tests\Feature;

use App\Handover\Sources\Source;
use App\Models\DailyIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Source::class, SampleWorkbook::source());
    }

    public function test_sync_updates_data_from_sheet(): void
    {
        $res = $this->postJson('/api/daily/sync', [
            'sejak' => '2026-10',
            'sampai' => '2026-10',
        ]);

        $res->assertOk();
        $res->assertJsonPath('ok', true);
        $res->assertJsonPath('baru', 12);

        $this->assertSame(12, DailyIssue::count());
    }

    public function test_sync_dari_baris_does_not_affect_earlier_rows_and_preserves_existing_numbers(): void
    {
        // 1. Misal di database server sudah ada issue nomor 300 (misalnya dibuat dari web)
        $webIssue = new DailyIssue([
            'tanggal' => '2026-10-01',
            'issue' => 'Issue lama di server hingga nomor 300',
            'status' => 'pending',
        ]);
        $webIssue->forceFill(['bulan' => '2026-10', 'nomor' => 300])->save();

        // 2. Misal baris 3 sheet sudah pernah ada di database dengan status custom dari web
        $row3 = new DailyIssue([
            'tanggal' => '2026-10-01',
            'sumber_tab' => 'Issue Oktober 2026',
            'sumber_baris' => 3,
            'issue' => 'Issue baris 3 sudah diedit di web',
            'status' => 'trouble',
            'diubah_web_at' => now(),
        ]);
        $row3->forceFill(['bulan' => '2026-10', 'nomor' => 1])->save();

        // 3. Jalankan sync mulai dari baris 5 ke bawah (seperti analogi baris 230 ke bawah)
        $res = $this->postJson('/api/daily/sync', [
            'sejak' => '2026-10',
            'sampai' => '2026-10',
            'dari_baris' => 5,
        ]);

        $res->assertOk();
        $res->assertJsonPath('ok', true);

        // Baris 3 dan 4 di sheet dilewati karena < 5 (tidak memengaruhi baris di atasnya)
        $this->assertNull(DailyIssue::where('sumber_baris', 4)->first());
        $this->assertSame('trouble', $row3->fresh()->status);
        $this->assertSame('Issue baris 3 sudah diedit di web', $row3->fresh()->issue);

        // Issue nomor 300 di database tidak terganggu
        $this->assertSame(300, $webIssue->fresh()->nomor);
        $this->assertSame('Issue lama di server hingga nomor 300', $webIssue->fresh()->issue);

        // Baris 5 ke bawah di bulan Oktober berhasil masuk sebagai issue baru dan mendapat nomor di atas 300
        $newIssuesOctober = DailyIssue::where('bulan', '2026-10')->where('sumber_baris', '>=', 5)->get();
        $this->assertNotEmpty($newIssuesOctober);
        foreach ($newIssuesOctober as $ni) {
            $this->assertGreaterThan(300, $ni->nomor);
        }
    }
}
