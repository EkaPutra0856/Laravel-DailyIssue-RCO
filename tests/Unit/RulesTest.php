<?php

namespace Tests\Unit;

use App\Handover\Category;
use App\Handover\ColumnMap;
use App\Handover\Dates;
use App\Handover\IssueService;
use App\Handover\RawRow;
use App\Handover\RawSheet;
use App\Handover\Rules;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RulesTest extends TestCase
{
    public function test_legend_colors(): void
    {
        $cols = new ColumnMap(tanggal: 0, aplikasi: null, team: null, pelapor: 1, status: 2, pic: 3);
        [$y, $o, $g] = [[255, 255, 0], [255, 153, 0], [0, 255, 0]];
        $rows = [
            new RawRow(1, ['2026-10-01', '1 ~a', null, null], array_fill(0, 4, null)),        // putih kosong -> pending
            new RawRow(2, ['2026-10-01', '1 ~a', null, null], array_fill(0, 4, $y)),          // kuning tanpa PIC -> progress
            new RawRow(3, ['2026-10-01', '1 ~a', 'oncek', 'Bias'], array_fill(0, 4, $o)),     // orange -> progress, eksternal
            new RawRow(4, ['2026-10-01', '1 ~a', 'oncek', 'Bias'], [$g, null, null, null]),   // hijau -> done
            new RawRow(5, ['2026-10-01', '1 ~a', 'pending', null], array_fill(0, 4, null)),   // status "pending" -> pending
        ];
        $out = [];
        foreach (Rules::buildIssues(new RawSheet('t', $rows), $cols) as $i) {
            $out[$i->issueNo] = $i;
        }
        $this->assertSame(Category::PENDING, $out[1]->category);
        $this->assertSame(Category::PROGRESS, $out[2]->category);
        $this->assertFalse($out[2]->waitingExternal);
        $this->assertSame(Category::PROGRESS, $out[3]->category);
        $this->assertTrue($out[3]->waitingExternal);
        $this->assertSame(Category::DONE, $out[4]->category);
        $this->assertSame(Category::PENDING, $out[5]->category);
    }

    public static function greens(): array
    {
        return [
            [[183, 225, 205], true],   // #b7e1cd
            [[217, 234, 211], true],   // #d9ead3 light green 3
            [[0, 255, 0], true],
            [[106, 168, 79], true],    // #6aa84f
            [[39, 78, 19], true],      // #274e13
            [[143, 206, 0], true],     // #8fce00
            [[255, 255, 255], false],
            [[255, 255, 0], false],    // kuning
            [[255, 242, 204], false],  // kuning muda
            [[0, 255, 255], false],    // cyan
            [[217, 217, 217], false],  // abu-abu
            [null, false],
        ];
    }

    #[DataProvider('greens')]
    public function test_is_green(?array $rgb, bool $green): void
    {
        $this->assertSame($green, Rules::isGreen($rgb));
    }

    public static function teams(): array
    {
        return [
            [null, '1 ~Aldi Pratama', 'Tim 1'],
            [null, '2 ~Putri', 'Tim 2'],
            [null, '3 temuan', 'Tim 3'],
            [null, 2.0, 'Tim 2'],
            [null, '1.0', 'Tim 1'],
            [null, 'Tim 3 - Budi', 'Tim 3'],
            [null, 'Aldi ~1', 'Tim 1'],
            [null, '62 811-1122-993 ~Aldi', 'ALL TEAM'],
            [null, '12 orang', 'ALL TEAM'],
            [null, 'IT Service Desk BRI', 'ALL TEAM'],
            ['2', '1 ~Aldi', 'Tim 2'],
            ['All', '1 ~Aldi', 'ALL TEAM'],     // kolom TEAM terisi -> tidak menebak dari pelapor
            ['3', 'temuan', 'Tim 3'],
            ['', '2 ~Putri', 'Tim 2'],
            [3, null, 'Tim 3'],
        ];
    }

    #[DataProvider('teams')]
    public function test_parse_team(mixed $team, mixed $pelapor, string $expected): void
    {
        $this->assertSame($expected, Rules::parseTeam($team, $pelapor));
    }

    public static function pics(): array
    {
        return [
            ['Hendrik', 'Hendrik'],
            ['  reynal ', 'reynal'],
            ['select * from tbl', ''],
            ['172.18.141.71', ''],
            ['https://bridrive/x', ''],
            ['D:\\backup\\db.mdf', ''],
            ["baris\nbaru", ''],
            ['nama yang sangat panjang sekali lebih dari 25', ''],
            ['-', ''],
            ['done', ''],
            ['30/12/1899', ''],
            [null, ''],
            [12.5, ''],
        ];
    }

    #[DataProvider('pics')]
    public function test_sanitize_pic(mixed $raw, string $expected): void
    {
        $this->assertSame($expected, Rules::sanitizePic($raw));
    }

    public static function cellDates(): array
    {
        return [
            [CarbonImmutable::create(2026, 10, 1, 8, 0), '2026-10-01'],
            [46296, '2026-10-01'],          // serial Google Sheets
            ['01/10/2026', '2026-10-01'],
            ['1/10/26', '2026-10-01'],
            ['1 Oktober 2026', '2026-10-01'],
            ['Kamis, 1 Okt 2026', '2026-10-01'],
            ['2026-10-01', '2026-10-01'],
            ['Tanggal', null],
            ['30/12/1899', null],
            ['', null],
            [null, null],
            [true, null],
        ];
    }

    #[DataProvider('cellDates')]
    public function test_parse_cell_date(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, Dates::parseCellDate($raw)?->toDateString());
    }

    private static function cols(): ColumnMap
    {
        return new ColumnMap(tanggal: 0, aplikasi: null, team: null, pelapor: 1, status: 2, pic: 3);
    }

    private static function rows(mixed ...$dates): RawSheet
    {
        $rows = [];
        foreach ($dates as $i => $d) {
            $rows[] = new RawRow($i + 3, [$d, '1 ~a', null, null], array_fill(0, 4, null));
        }

        return new RawSheet('Issue Juli 2026', $rows);
    }

    private static function dates(array $issues): array
    {
        return array_map(fn ($i) => $i->tanggal->toDateString(), $issues);
    }

    public function test_missing_date_uses_previous_row(): void
    {
        $out = Rules::buildIssues(self::rows('2026-07-02', ' ', null), self::cols());
        $this->assertSame([3, 4, 5], array_map(fn ($i) => $i->issueNo, $out));
        $this->assertSame(['2026-07-02', '2026-07-02', '2026-07-02'], self::dates($out));
    }

    public function test_empty_row_without_issue_skipped(): void
    {
        $sheet = new RawSheet('t', [
            new RawRow(3, ['2026-07-02', '1 ~a', null, null], array_fill(0, 4, null)),
            new RawRow(4, [null, null, null, null], array_fill(0, 4, null)),
        ]);
        $this->assertSame([3], array_map(fn ($i) => $i->issueNo, Rules::buildIssues($sheet, self::cols())));
    }

    public function test_typo_year_fixed_to_tab_year(): void
    {
        $issues = Rules::buildIssues(self::rows('2026-07-04', '2025-07-05', '2015-07-06', '2026-08-01'), self::cols());
        $this->assertSame(['2026-07-04', '2026-07-05', '2026-07-06', '2026-08-01'],
            self::dates(IssueService::fixTypoDates($issues, 2026, 7)));
    }

    public function test_wrong_month_uses_previous_date(): void
    {
        $issues = Rules::buildIssues(self::rows('2026-07-10', '2026-01-11'), self::cols());
        $this->assertSame(['2026-07-10', '2026-07-10'], self::dates(IssueService::fixTypoDates($issues, 2026, 7)));
    }

    public static function tabs(): array
    {
        return [
            [2026, 10, 'Issue Oktober 2026'],
            [2026, 3, 'Issue Mar 2026'],
            [2026, 1, 'Issue_Jan_2026'],
            [2025, 9, 'Issue Sept 2025'],
            [2025, 8, 'Issue Ags 25'],
            [2025, 5, ' Issue Mei 25'],
            [2024, 8, 'Issue Agst 24'],
            [2023, 5, 'Issue May 23'],
            [2026, 11, null],
        ];
    }

    #[DataProvider('tabs')]
    public function test_find_month_tab(int $year, int $month, ?string $expected): void
    {
        $titles = ['Issue Oktober 2026', 'Issue September 2026', 'Issue Mar 2026', 'Issue_Jan_2026', 'Issue Des 2025',
            'Issue Sept 2025', 'Issue Ags 25', 'Summary Issue Monthly', ' Issue Mei 25', 'Issue Agst 24',
            'Issue May 23', 'Sheet1'];
        $this->assertSame($expected, IssueService::findMonthTab($titles, $year, $month));
    }
}
