<?php

namespace Tests\Unit;

use App\Handover\Import\SheetImporter;
use App\Handover\RawRow;
use ReflectionClass;
use Tests\TestCase;

class SheetImporterTimeTest extends TestCase
{
    private function parseTime(mixed $value): ?string
    {
        $importer = new SheetImporter($this->createMock(\App\Handover\Sources\Source::class));
        $ref = new ReflectionClass($importer);
        $method = $ref->getMethod('time');
        $method->setAccessible(true);

        $row = new RawRow(rowNumber: 1, values: [$value], colors: []);
        return $method->invoke($importer, $row, 0);
    }

    public function test_parses_various_time_formats(): void
    {
        // Format jam biasa (string)
        $this->assertSame('07:20:00', $this->parseTime('7:20'));
        $this->assertSame('07:20:00', $this->parseTime('07:20'));
        $this->assertSame('07:20:00', $this->parseTime('7.20'));
        $this->assertSame('07:20:00', $this->parseTime('07:20:00'));
        $this->assertSame('19:39:00', $this->parseTime('19:39'));

        // Format angka / float dari Excel
        $this->assertSame('19:39:00', $this->parseTime(19.39));
        $this->assertSame('14:44:00', $this->parseTime(14.44));

        // Pecahan serial time Excel (mis. 07:20:00 = (7*3600 + 20*60)/86400)
        $this->assertSame('07:20:00', $this->parseTime((7 * 3600 + 20 * 60) / 86400));

        // String dengan multiple jam
        $this->assertSame('08:37:00', $this->parseTime('08:37 & 09:49'));

        // Format datetime lengkap
        $this->assertSame('07:20:00', $this->parseTime('2026-11-09 07:20:00'));
        $this->assertSame('07:20:00', $this->parseTime('1899-12-30T07:20:00.000Z'));

        // Nilai kosong / non-time
        $this->assertNull($this->parseTime(null));
        $this->assertNull($this->parseTime(''));
        $this->assertNull($this->parseTime('Done'));
        $this->assertNull($this->parseTime('temuan'));
    }
}
