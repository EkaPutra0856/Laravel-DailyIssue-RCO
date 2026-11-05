<?php

namespace Tests\Unit;

use App\Handover\Category;
use App\Handover\Commands;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\Sources\AppsScriptSource;
use App\Handover\Sources\SourceError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AppsScriptSourceTest extends TestCase
{
    private const URL = 'https://script.google.com/macros/s/x/exec';
    private const HEADER = ['Tanggal', 'ISSUE', 'Aplikasi', 'Pelapor', 'Media', 'Penerima', 'Pencatat', 'Waktu Lapor',
        'Status', 'Pic', '', '', '', 'Note', 'IP', 'Dok'];
    private const W = '#ffffff';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** Respons palsu Web App: tab Oktober dengan 3 issue (pending, progress, hijau). */
    private static function fake(Request $request, bool $withRows = false): \GuzzleHttp\Promise\PromiseInterface
    {
        if ($request['action'] === 'titles') {
            return Http::response(['ok' => true, 'titles' => ['Sheet1', 'Issue Oktober 2026'], 'gids' => ['Issue Oktober 2026' => 352]]);
        }
        $blank = array_fill(0, 16, '');
        $values = [
            ['Column 1', ...array_fill(0, 15, '')],
            self::HEADER,
            ['2026-10-01', 'x', 'MPAYS', '1 ~Aldi', '', '', '', '', '', '', '', '', '', 'select *', '', ''],
            ['2026-10-02', 'x', 'QRIS', 2, '', '', '', '', 'oncek', 'Hendrik', '', '', '', '', '', ''],
            ['2026-10-03', 'x', 'JCB', '3 ~B', '', '', '', '', '', '', '', '', '', '', '', ''],
        ];
        $bgs = [array_fill(0, 16, self::W), array_fill(0, 16, self::W), array_fill(0, 16, self::W),
            array_fill(0, 16, self::W), ['#b7e1cd', ...array_fill(0, 15, self::W)]];
        $sheet = ['title' => 'Issue Oktober 2026', 'values' => $values, 'backgrounds' => $bgs];
        if ($withRows) {
            // Web App baru: baris kosong 5-6 dan 8-10 dilewati, ada baris "nyasar" di 11.
            $sheet['rows'] = [1, 2, 3, 4, 7, 11];
            $sheet['values'] = [...array_slice($values, 0, 4), $values[4], ['', '', '', '', '', '', '', '', '', 'nyasar', ...array_fill(0, 6, '')]];
            $sheet['backgrounds'] = [...array_slice($bgs, 0, 4), $bgs[4], array_fill(0, 16, self::W)];
        }

        return Http::response(['ok' => true, 'sheets' => [$sheet]]);
    }

    private static function byNo(array $issues): array
    {
        $out = [];
        foreach ($issues as $i) {
            $out[$i->issueNo] = $i;
        }

        return $out;
    }

    public function test_reads_and_classifies(): void
    {
        Http::fake([self::URL.'*' => fn (Request $r) => self::fake($r)]);
        $src = new AppsScriptSource(self::URL, 'k');
        [$issues, $warnings] = (new IssueService($src))->collect(Commands::parse('/rekap Oktober 2026', Dates::make(2026, 10, 29)));
        $by = self::byNo($issues);
        $this->assertSame([], $warnings);
        $this->assertSame(Category::PENDING, $by[3]->category);
        $this->assertSame('Tim 1', $by[3]->team);
        $this->assertSame(Category::PROGRESS, $by[4]->category);
        $this->assertSame('Tim 2', $by[4]->team);
        $this->assertSame(Category::DONE, $by[5]->category);
        $this->assertSame('hijau', $by[5]->doneReason);
        $this->assertSame(['Issue Oktober 2026' => '352'], $src->sheetGids());
    }

    public function test_rows_field_keeps_real_row_numbers(): void
    {
        Http::fake([self::URL.'*' => fn (Request $r) => self::fake($r, withRows: true)]);
        $src = new AppsScriptSource(self::URL, 'k');
        $sheet = $src->fetch(['Issue Oktober 2026'])[0];
        $this->assertSame([1, 2, 3, 4, 7, 11], array_map(fn ($r) => $r->rowNumber, $sheet->rows));
        $this->assertSame([183, 225, 205], $sheet->rows[4]->colors[0]);  // warna ikut baris yang benar
        [$issues] = (new IssueService($src))->collect(Commands::parse('/rekap Oktober 2026', Dates::make(2026, 10, 29)));
        $this->assertSame('hijau', self::byNo($issues)[7]->doneReason);
    }

    public static function blocked(): array
    {
        return [['sync'], ['verify'], ['clear'], ['']];
    }

    #[DataProvider('blocked')]
    public function test_blocks_non_read_actions(string $action): void
    {
        Http::fake();
        try {
            (new AppsScriptSource(self::URL, 'k'))->call($action);
            $this->fail('harus ditolak');
        } catch (SourceError $e) {
            $this->assertStringContainsString('diblokir', $e->getMessage());
        }
        Http::assertNothingSent();  // ditolak sebelum menyentuh jaringan
    }

    public function test_titles_cached_and_forgettable(): void
    {
        Http::fake([self::URL.'*' => Http::response(['ok' => true, 'titles' => ['Issue Oktober 2026'], 'gids' => ['Issue Oktober 2026' => 1]])]);
        $src = new AppsScriptSource(self::URL, 'k');
        $src->listTitles();
        $src->sheetGids();
        $src->listTitles();
        Http::assertSentCount(1);
        $src->forgetTitles();
        $src->listTitles();
        Http::assertSentCount(2);
    }

    public function test_retries_connection_errors(): void
    {
        $calls = 0;
        Http::fake([self::URL.'*' => function () use (&$calls) {
            if (++$calls === 1) {
                throw new ConnectionException('cURL error 28: Connection timed out after 15001 milliseconds');
            }

            return Http::response(['ok' => true, 'titles' => ['Issue Oktober 2026']]);
        }]);
        $this->assertSame(['Issue Oktober 2026'], (new AppsScriptSource(self::URL, 'k'))->listTitles());
        $this->assertSame(2, $calls);
    }

    public function test_rejects_with_message(): void
    {
        Http::fake([self::URL.'*' => Http::response(['ok' => false, 'error' => 'unauthorized'])]);
        $this->expectExceptionMessage('Apps Script menolak: unauthorized');
        (new AppsScriptSource(self::URL, 'salah'))->listTitles();
    }

    public function test_hex_colors(): void
    {
        $this->assertNull(AppsScriptSource::hex('#ffffff'));
        $this->assertNull(AppsScriptSource::hex(null));
        $this->assertSame([255, 255, 0], AppsScriptSource::hex('#FFFF00'));
    }
}
