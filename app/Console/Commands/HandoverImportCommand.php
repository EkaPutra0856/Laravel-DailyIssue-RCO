<?php

namespace App\Console\Commands;

use App\Handover\Dates;
use App\Handover\Import\SheetImporter;
use App\Handover\Sources\Source;
use App\Handover\Sources\SourceError;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Salin issue lama dari sheet asli ke database (sheet asli hanya dibaca). */
class HandoverImportCommand extends Command
{
    protected $signature = 'handover:import
        {--sejak= : mulai bulan YYYY-MM (default: 2 bulan lalu)}
        {--sampai= : sampai bulan YYYY-MM (default: bulan ini)}
        {--timpa : timpa baris yang sudah pernah diimpor (perubahan dari web ikut tertimpa)}
        {--lengkapi : untuk baris yang sudah ada, hanya isi Note / Aplikasi (IP) / Dokumentasi yang masih kosong}
        {--sinkron : tambah issue baru + perbarui yang berubah di sheet, kecuali yang sudah diubah/dihapus lewat web}';

    protected $description = 'Impor issue dari tab "Issue <Bulan> <Tahun>" spreadsheet asli ke database (read-only)';

    private static function month(?string $s, CarbonImmutable $default): ?CarbonImmutable
    {
        if ($s === null || $s === '') {
            return $default;
        }

        return preg_match('/^(\d{4})-(\d{1,2})$/', $s, $m) ? Dates::make((int) $m[1], (int) $m[2], 1) : null;
    }

    public function handle(): int
    {
        $now = CarbonImmutable::now(config('handover.timezone'));
        $thisMonth = Dates::make($now->year, $now->month, 1);
        $from = self::month($this->option('sejak'), $thisMonth->subMonthsNoOverflow(2));
        $to = self::month($this->option('sampai'), $thisMonth);
        if (! $from || ! $to || $to->lessThan($from)) {
            $this->error('--sejak / --sampai harus berformat YYYY-MM, dan sejak <= sampai');

            return 1;
        }

        try {
            $importer = new SheetImporter(app(Source::class));
            $tabs = $importer->tabsFor(Dates::monthsBetween($from, $to));
        } catch (SourceError $e) {
            $this->error("❌ {$e->getMessage()}");

            return 1;
        }
        if (! $tabs) {
            $this->warn('Tidak ada tab Issue untuk periode itu.');

            return 0;
        }

        $total = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'dilengkapi' => 0];
        foreach ($tabs as $tab => [$y, $m]) {
            $this->line("Membaca '{$tab}' ...");
            try {
                $stat = $importer->importTab($tab, $y, $m, (bool) $this->option('timpa'), (bool) $this->option('lengkapi'), (bool) $this->option('sinkron'));
            } catch (SourceError $e) {
                $this->error("  ❌ {$e->getMessage()}");

                continue;
            }
            $this->line("  baru {$stat['baru']}, diperbarui {$stat['diperbarui']}, dilengkapi {$stat['dilengkapi']}, sudah ada {$stat['dilewati']}");
            foreach ($stat as $k => $v) {
                $total[$k] += $v;
            }
        }
        $apps = SheetImporter::rebuildAplikasi();
        $this->info("Selesai: {$total['baru']} issue baru, {$total['diperbarui']} diperbarui, {$total['dilengkapi']} dilengkapi, {$total['dilewati']} sudah ada. "
            ."{$apps} aplikasi baru untuk rekomendasi team.");

        return 0;
    }
}
