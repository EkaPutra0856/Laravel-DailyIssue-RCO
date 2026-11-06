<?php

namespace App\Http\Controllers\Daily;

use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\PeriodeRequest;
use App\Http\Requests\Daily\SimpanIssueRequest;
use App\Models\DailyIssue;
use App\Services\Daily\SimpanLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Daftar, detail, dan laporan issue oleh polisi. */
class IssueController extends Controller
{
    public function __construct(private SimpanLaporan $laporan) {}

    public function index(PeriodeRequest $request): JsonResponse
    {
        [$awal, $akhir] = $request->rentang();
        $issues = DailyIssue::periode($awal, $akhir)->terbaru()->with('lampiran')->get();

        return self::json([
            'start' => $awal->toDateString(),
            'end' => $akhir->toDateString(),
            'fetched_at' => CarbonImmutable::now(config('handover.timezone'))->format('Y-m-d\TH:i:sP'),
            'issues' => $issues->map(fn (DailyIssue $issue) => $issue->toRow())->all(),
        ]);
    }

    public function show(DailyIssue $issue): JsonResponse
    {
        return self::json($issue->load('lampiran')->toRow());
    }

    /** Issue baru selalu berstatus pending; bagian engineer kosong. */
    public function store(SimpanIssueRequest $request): JsonResponse
    {
        $issue = $this->laporan->simpan($request->isian(), $request->lampiranBaru());

        return self::json($issue->toRow(), 201);
    }

    /** Polisi memperbaiki laporan dan lampiran. Status dan PIC tidak berubah. */
    public function update(SimpanIssueRequest $request, DailyIssue $issue): JsonResponse
    {
        $issue = $this->laporan->simpan($request->isian(), $request->lampiranBaru(), $request->lampiranDihapus(), $issue);

        return self::json($issue->toRow());
    }

    /** Soft delete: hilang dari tabel, rekap, bot, dan Excel, tapi data dan lampiran tetap tersimpan. */
    public function destroy(Request $request, DailyIssue $issue): JsonResponse
    {
        $issue->hapusOleh((string) (session('portal_user.nama') ?: $request->input('nama', '')));

        return self::json(['deleted' => $issue->id, 'nomor' => $issue->nomor, 'bulan' => $issue->bulan]);
    }
}
