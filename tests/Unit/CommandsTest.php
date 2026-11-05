<?php

namespace Tests\Unit;

use App\Handover\Category;
use App\Handover\CommandError;
use App\Handover\Commands;
use App\Handover\Dates;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    private static function today()
    {
        return Dates::make(2026, 10, 29);
    }

    public static function periods(): array
    {
        return [
            ['', '2026-10-29', '2026-10-29'],
            ['kemarin', '2026-10-28', '2026-10-28'],
            ['bulan ini', '2026-10-01', '2026-10-31'],
            ['Oktober', '2026-10-01', '2026-10-31'],
            ['februari 2028', '2028-02-01', '2028-02-29'],
            ['13 Oktober 2026', '2026-10-13', '2026-10-13'],
            ['13/10/2026', '2026-10-13', '2026-10-13'],
            ['13-10-2026', '2026-10-13', '2026-10-13'],
            ['5 Oktober 2026 : 7 Oktober 2026', '2026-10-05', '2026-10-07'],
            ['05/10/2026 : 07/10/2026', '2026-10-05', '2026-10-07'],
            ['28 September 2026 : 2 Oktober 2026', '2026-09-28', '2026-10-02'],
            ['28 September s/d 2 Oktober 2026', '2026-09-28', '2026-10-02'],
            ['28 Des 2025 - 3 Jan 2026', '2025-12-28', '2026-01-03'],
            ['28 Desember : 3 Januari 2026', '2025-12-28', '2026-01-03'],
            ['5-7 Oktober 2026', '2026-10-05', '2026-10-07'],
            ['5 : 7 Oktober 2026', '2026-10-05', '2026-10-07'],
            ['September : November 2026', '2026-09-01', '2026-11-30'],
            ['7 Oktober 2026 : 5 Oktober 2026', '2026-10-05', '2026-10-07'],
            ['minggu ini', '2026-10-26', '2026-10-29'],
            ['bulan lalu', '2026-09-01', '2026-09-30'],
        ];
    }

    #[DataProvider('periods')]
    public function test_parse_period(string $text, string $start, string $end): void
    {
        [$s, $e] = Commands::parsePeriod($text, self::today());
        $this->assertSame([$start, $end], [$s->toDateString(), $e->toDateString()]);
    }

    public static function invalidPeriods(): array
    {
        return [['besok lusa'], ['32 Oktober 2026'], ['Oktober : Foo'], ['1 2 3']];
    }

    #[DataProvider('invalidPeriods')]
    public function test_parse_period_invalid(string $text): void
    {
        $this->expectException(CommandError::class);
        Commands::parsePeriod($text, self::today());
    }

    public function test_commands_categories_and_flags(): void
    {
        $q = Commands::parse('/rekap Oktober', self::today());
        $this->assertSame([Category::PENDING, Category::PROGRESS], $q->categories);
        $this->assertFalse($q->detail);
        $this->assertFalse($q->split);

        $q = Commands::parse('/rekapdetail@HandoverBot Oktober 2026', self::today());
        $this->assertTrue($q->detail);
        $this->assertSame('2026-10-01', $q->start->toDateString());

        $q = Commands::parse('/pending 13 Oktober 2026 detail', self::today());
        $this->assertSame([Category::PENDING], $q->categories);
        $this->assertTrue($q->detail);
        $this->assertSame('2026-10-13', $q->start->toDateString());

        $this->assertSame([Category::PROGRESS], Commands::parse('/progress', self::today())->categories);
        $this->assertSame([Category::DONE], Commands::parse('/done Oktober', self::today())->categories);

        $q = Commands::parse('/doneprogress Oktober', self::today());
        $this->assertSame([Category::DONE, Category::PROGRESS], $q->categories);
        $this->assertTrue($q->split);

        $this->assertTrue(Commands::parse('/rekap Oktober pisah', self::today())->split);

        $q = Commands::parse('/rekap done pending Oktober', self::today());
        $this->assertSame([Category::DONE, Category::PENDING], $q->categories);
        $this->assertTrue($q->split);
    }

    public function test_unknown_command(): void
    {
        $this->expectException(CommandError::class);
        Commands::parse('/foo', self::today());
    }

    public function test_invalid_date_message(): void
    {
        $this->expectExceptionMessage('Tanggal 40/10/2026 tidak valid.');
        Commands::parse('/rekap 40/10/2026', self::today());
    }
}
