<?php

namespace App\Http\Controllers\Daily;

use App\Handover\Dates;
use App\Handover\Import\SheetImporter;
use App\Handover\Sources\Source;
use App\Handover\Sources\SourceError;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Sinkronkan issue dari spreadsheet asli ke database (logika update-data.bat / handover:import --sinkron).
 * Issue baru ditambahkan; issue yang sudah diubah/dihapus lewat web tidak ditimpa;
 * jika dari_baris ditentukan, baris-baris sebelum nomor baris tersebut dilewati (tidak memengaruhi data di atasnya).
 */
class SyncController extends Controller
{
    private static function parseMonth(?string $s): ?CarbonImmutable
    {
        if ($s === null || $s === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{1,2})/', $s, $m)) {
            return Dates::make((int) $m[1], (int) $m[2], 1);
        }

        return Dates::fromIso($s)?->startOfMonth();
    }

    public function __invoke(Request $request, Source $source): JsonResponse
    {
        $now = CarbonImmutable::now(config('handover.timezone'));
        $thisMonth = Dates::make($now->year, $now->month, 1);

        // Rentang bulan: dari parameter 'sejak'/'sampai' atau 'start'/'end' (default: bulan lalu s/d bulan ini)
        $dariBulan = self::parseMonth($request->input('sejak') ?: $request->input('start'))
            ?? $thisMonth->subMonthsNoOverflow(1);
        $sampaiBulan = self::parseMonth($request->input('sampai') ?: $request->input('end'))
            ?? $thisMonth;

        if ($sampaiBulan->lessThan($dariBulan)) {
            return self::json(['error' => 'Rentang bulan tidak valid: sejak harus <= sampai'], 422);
        }

        $dariBaris = $request->filled('dari_baris') ? (int) $request->input('dari_baris') : null;

        try {
            $importer = new SheetImporter($source);
            $months = Dates::monthsBetween($dariBulan, $sampaiBulan);
            $tabs = $importer->tabsFor($months);

            if (! $tabs) {
                return self::json([
                    'ok' => true,
                    'message' => 'Tidak ada tab Issue untuk periode tersebut.',
                    'baru' => 0,
                    'diperbarui' => 0,
                    'dilewati' => 0,
                ]);
            }

            $total = ['baru' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'dilengkapi' => 0];
            foreach ($tabs as $tab => [$y, $m]) {
                $stat = $importer->importTab(
                    tab: $tab,
                    year: $y,
                    month: $m,
                    overwrite: false,
                    lengkapi: false,
                    sinkron: true,
                    dariBaris: $dariBaris,
                );
                foreach ($stat as $k => $v) {
                    $total[$k] += $v;
                }
            }

            $apps = SheetImporter::rebuildAplikasi();

            $pesan = "Update data selesai: {$total['baru']} issue baru ditambahkan, {$total['diperbarui']} diperbarui.";
            if ($total['dilewati'] > 0) {
                $pesan .= " ({$total['dilewati']} dilewati/tidak berubah)";
            }

            return self::json([
                'ok' => true,
                'message' => $pesan,
                'baru' => $total['baru'],
                'diperbarui' => $total['diperbarui'],
                'dilewati' => $total['dilewati'],
                'aplikasi_baru' => $apps,
            ]);
        } catch (SourceError $e) {
            return self::json(['error' => $e->getMessage()], 502);
        } catch (Throwable $e) {
            return self::json(['error' => 'Gagal sinkron data: '.$e->getMessage()], 500);
        }
    }
}
