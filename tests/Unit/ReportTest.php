<?php

namespace Tests\Unit;

use App\Handover\Category;
use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\Report;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

class ReportTest extends TestCase
{
    private const TITLE = 'Handover Polisi – Team RCO';

    private function run_(string $text): array
    {
        $q = Commands::parse($text, Dates::make(2026, 10, 29));
        [$issues, $warnings] = (new IssueService(SampleWorkbook::source()))->collect($q);

        return [$q, $issues, $warnings];
    }

    private static function byNo(array $issues): array
    {
        $out = [];
        foreach ($issues as $i) {
            $out[$i->issueNo] = $i;
        }

        return $out;
    }

    public function test_classification(): void
    {
        [, $issues, $warnings] = $this->run_('/rekap Oktober 2026');
        $this->assertSame([], $warnings);
        $by = self::byNo($issues);
        $this->assertArrayNotHasKey(14, $by);  // tanggal 30 September difilter
        $this->assertSame(Category::PENDING, $by[3]->category);
        $this->assertSame('Tim 1', $by[3]->team);
        $this->assertSame(Category::PROGRESS, $by[4]->category);
        $this->assertSame('Hendrik', $by[4]->pic);
        $this->assertSame('status', $by[5]->doneReason);
        $this->assertSame('hijau', $by[6]->doneReason);
        $this->assertSame(Category::PENDING, $by[7]->category);
        $this->assertSame('', $by[7]->pic);  // SQL tidak bocor
        $this->assertSame(Category::PROGRESS, $by[8]->category);
        $this->assertSame('Tim 2', $by[8]->label());
        $this->assertSame('Tim 2', $by[8]->team);
        $this->assertSame('ALL TEAM', $by[9]->team);
        $this->assertSame(Category::PENDING, $by[10]->category);
        $this->assertSame('pic', $by[11]->doneReason);
        $this->assertSame('hijau', $by[12]->doneReason);
        $this->assertSame(Category::PROGRESS, $by[13]->category);  // kuning bukan done
    }

    public function test_rekap_message_format(): void
    {
        [$q, $issues, $w] = $this->run_('/rekap Oktober 2026');
        $msgs = Report::buildMessages($issues, $q, self::TITLE, $w);
        $this->assertCount(1, $msgs);
        $expected = <<<'TXT'
<b>Handover Polisi – Team RCO</b>

01/10/2026 - 31/10/2026

📌 <b>Pendingan Issue (Belum Dikerjakan):</b>

<b>Tim 1</b>
01/10/2026 — Issue 3
05/10/2026 — Issue 7
10/10/2026 — Issue 10

<b>Tim 2</b>
<i>Tidak ada pendingan</i>

<b>Tim 3</b>
<i>Tidak ada pendingan</i>

<b>ALL TEAM</b>
09/10/2026 — Issue 9

⏳ <b>Issue On Progress (Kuning):</b>

01/10/2026 — Tim 2 — Issue 4 — Hendrik
07/10/2026 — Tim 2 — Issue 8 — Tim 2
13/10/2026 — Tim 2 — Issue 13 — Puji

------------------------------------
Total Pending: 4 Issue (Putih)
Total On Progress: 3 Issue (Kuning)
Total Keseluruhan: 7 Issue

Mohon bantuannya untuk ditelusuri rekan-rekan engineer
Terima kasih.
TXT;
        $this->assertSame(str_replace("\r\n", "\n", $expected), $msgs[0]);
        $this->assertStringNotContainsString('select', $msgs[0]);
        $this->assertStringNotContainsString('172.18', $msgs[0]);
    }

    public function test_detail_shows_app_only(): void
    {
        [$q, $issues, $w] = $this->run_('/rekapdetail 1 Oktober 2026');
        $msg = Report::buildMessages($issues, $q, self::TITLE, $w)[0];
        $this->assertStringContainsString('<b>Handover Polisi (Detail) – Team RCO</b>', $msg);
        $this->assertStringContainsString('01/10/2026 — Issue 3 — MPAYS', $msg);
        $this->assertStringContainsString('01/10/2026 — Tim 2 — Issue 4 — QRIS — Hendrik', $msg);
        $this->assertStringNotContainsString('deskripsi issue', $msg);
    }

    public function test_cross_month_and_header_fallback(): void
    {
        [$q, $issues, $w] = $this->run_('/rekap 28 September 2026 : 2 Oktober 2026');
        $nos = array_map(fn ($i) => [$i->sheet, $i->issueNo, $i->category->value], $issues);
        $this->assertContains(['Issue September 2026', 3, 'pending'], $nos);
        $this->assertContains(['Issue September 2026', 4, 'progress'], $nos);
        $this->assertContains(['Issue Oktober 2026', 3, 'pending'], $nos);
        $msg = Report::buildMessages($issues, $q, self::TITLE, $w)[0];
        $this->assertStringContainsString('28/09/2026 - 02/10/2026', $msg);
        $this->assertStringContainsString('28/09/2026 — Issue 3', $msg);
    }

    public function test_missing_tab_warning(): void
    {
        [$q, $issues, $w] = $this->run_('/rekap November 2026');
        $this->assertSame(["Tab 'Issue November 2026' tidak ditemukan"], $w);
        $msg = Report::buildMessages($issues, $q, self::TITLE, $w)[0];
        $this->assertStringContainsString('⚠️', $msg);
        $this->assertStringContainsString('Total Keseluruhan: 0 Issue', $msg);
    }

    public function test_doneprogress_two_bubbles(): void
    {
        [$q, $issues, $w] = $this->run_('/doneprogress Oktober 2026');
        $msgs = Report::buildMessages($issues, $q, self::TITLE, $w);
        $this->assertCount(2, $msgs);
        $this->assertStringContainsString('✅ <b>Issue Done (Hijau):</b>', $msgs[0]);
        $this->assertStringContainsString('Total Done: 4 Issue (Hijau)', $msgs[0]);
        $this->assertStringContainsString('02/10/2026 — ALL TEAM — Issue 6 — ALL TEAM', $msgs[0]);
        $this->assertStringContainsString('Kuning', $msgs[1]);
        $this->assertStringContainsString('Total Keseluruhan: 7 Issue', $msgs[1]);
        $this->assertStringNotContainsString('Total Keseluruhan', $msgs[0]);
    }

    public function test_pending_only(): void
    {
        [$q, $issues, $w] = $this->run_('/pending Oktober 2026');
        $msg = Report::buildMessages($issues, $q, self::TITLE, $w)[0];
        $this->assertStringNotContainsString('On Progress', $msg);
        $this->assertStringContainsString('Total Pending: 4 Issue (Putih)', $msg);
        $this->assertStringNotContainsString('Total Keseluruhan', $msg);
    }

    public function test_chunking_keeps_lines_whole(): void
    {
        $lines = array_map(fn ($n) => "<b>Tim 1</b> 01/10/2026 — Issue {$n}", range(0, 499));
        $parts = Report::chunk($lines);
        $this->assertGreaterThan(1, count($parts));
        foreach ($parts as $n => $p) {
            $this->assertLessThanOrEqual(Report::MAX_LEN + 40, mb_strlen($p));
            $this->assertStringEndsWith('<i>(Part '.($n + 1).'/'.count($parts).')</i>', $p);
            $this->assertSame(substr_count($p, '<b>'), substr_count($p, '</b>'));
        }
    }

    public function test_escapes_html_like_python(): void
    {
        $this->assertSame('a &amp; &lt;b&gt; &quot;c&quot; &#x27;d&#x27;', \App\Handover\Py::escape('a & <b> "c" \'d\''));
    }
}
