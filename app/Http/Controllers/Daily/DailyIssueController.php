<?php

namespace App\Http\Controllers\Daily;

use App\Handover\Teams;
use App\Http\Controllers\Controller;
use App\Http\Requests\Daily\FollowUpRequest;
use App\Http\Requests\Daily\PeriodeRequest;
use App\Http\Requests\Daily\SimpanIssueRequest;
use App\Http\Requests\Daily\TindakLanjutRequest;
use App\Http\Resources\DailyIssueResource;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use App\Services\Daily\SimpanLaporan;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Throwable;

/**
 * Controller utama siklus hidup Daily Issue:
 * - Halaman view dashboard
 * - CRUD Issue polisi
 * - Tindak lanjut engineer
 * - Tracking follow-up
 * - Rekomendasi nama aplikasi
 */
class DailyIssueController extends Controller
{
    public function __construct(private SimpanLaporan $laporan) {}

    /** Halaman utama Daily Issue (?role=polisi|engineer, ?issue=ID, ?embed=1). */
    public function halaman(): View
    {
        return view('daily', [
            'teams' => Teams::ALL,
            'statuses' => DailyIssue::STATUS_LABEL,
            'engineerStatuses' => DailyIssue::ENGINEER_STATUSES,
            'media' => DailyIssue::MEDIA,
            'mediaDefault' => DailyIssue::MEDIA_DEFAULT,
            'portalUrl' => config('handover.web.portal_url'),
            'portalNama' => session('portal_user.nama', ''),
        ]);
    }

    /** Daftar issue dalam rentang tanggal tertentu (default: hari ini). */
    public function index(PeriodeRequest $request): JsonResponse
    {
        [$awal, $akhir] = $request->rentang();
        $issues = DailyIssue::periode($awal, $akhir)->terbaru()->with('lampiran')->get();

        return self::json([
            'start' => $awal->toDateString(),
            'end' => $akhir->toDateString(),
            'fetched_at' => CarbonImmutable::now(config('handover.timezone'))->format('Y-m-d\TH:i:sP'),
            'issues' => DailyIssueResource::collection($issues),
        ]);
    }

    /** Detail satu issue beserta lampirannya. */
    public function show(DailyIssue $issue): JsonResponse
    {
        return self::json(new DailyIssueResource($issue->load('lampiran')));
    }

    /** Polisi mencatat issue baru (status otomatis pending). */
    public function store(SimpanIssueRequest $request): JsonResponse
    {
        $issue = $this->laporan->simpan($request->isian(), $request->lampiranBaru());

        return self::json(new DailyIssueResource($issue), 201);
    }

    /** Polisi mengedit laporan atau lampiran issue. */
    public function update(SimpanIssueRequest $request, DailyIssue $issue): JsonResponse
    {
        $issue = $this->laporan->simpan($request->isian(), $request->lampiranBaru(), $request->lampiranDihapus(), $issue);

        return self::json(new DailyIssueResource($issue));
    }

    /** Polisi menghapus issue (soft delete). */
    public function destroy(Request $request, DailyIssue $issue): JsonResponse
    {
        $issue->hapusOleh((string) (session('portal_user.nama') ?: $request->input('nama', '')));

        return self::json(['deleted' => $issue->id, 'nomor' => $issue->nomor, 'bulan' => $issue->bulan]);
    }

    /** Engineer menindaklanjuti issue (wajib PIC dan status progress/trouble/done). */
    public function tindakLanjut(TindakLanjutRequest $request, DailyIssue $issue): JsonResponse
    {
        $data = $request->validated();
        $hapus = $request->lampiranDihapus();
        $tersimpan = [];

        try {
            DB::transaction(function () use ($issue, $data, $hapus, $request, &$tersimpan) {
                $issue->update([
                    'status' => $data['status'],
                    'follow_up' => $data['status'] === 'done' ? false : $issue->follow_up,
                    'pic' => $data['pic'],
                    'waktu_mulai' => ($data['waktu_mulai'] ?? null) ?: null,
                    'tanggal_finish' => ($data['tanggal_finish'] ?? null) ?: null,
                    'waktu_selesai' => ($data['waktu_selesai'] ?? null) ?: null,
                    'note' => ($data['note'] ?? null) ?: null,
                    'aplikasi_ip' => ($data['aplikasi_ip'] ?? null) ?: null,
                    'dokumentasi' => ($data['dokumentasi'] ?? null) ?: null,
                    'diubah_web_at' => now(),
                ]);

                $hapus->each->delete();
                foreach ($request->lampiranBaru() as $file) {
                    $path = $file->store('lampiran/'.now()->format('Y/m'), 'local');
                    $tersimpan[] = $path;
                    $issue->lampiran()->create([
                        'nama' => mb_substr($file->getClientOriginalName() ?: 'lampiran', 0, 255),
                        'path' => $path,
                        'mime' => $file->getMimeType() ?: 'application/octet-stream',
                        'ukuran' => $file->getSize(),
                    ]);
                }
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($tersimpan);
            throw $e;
        }

        Storage::disk('local')->delete($hapus->pluck('path')->all());

        return self::json(new DailyIssueResource($issue->fresh()->load('lampiran')));
    }

    /** Daftar issue yang ditandai butuh follow-up dan belum Done. */
    public function followUps(): JsonResponse
    {
        $issues = DailyIssue::followUpAktif()->terbaru()->with('lampiran')->get();

        return self::json(DailyIssueResource::collection($issues));
    }

    /** Tandai atau cabut penandaan follow-up. */
    public function toggleFollowUp(FollowUpRequest $request, DailyIssue $issue): JsonResponse
    {
        $followUp = $request->validated('follow_up');
        if ($followUp && $issue->status === 'done') {
            return self::json(['error' => 'Issue berstatus Done tidak dapat ditambahkan ke follow-up.'], 422);
        }

        $issue->update(['follow_up' => $followUp]);

        return self::json(new DailyIssueResource($issue->fresh()->load('lampiran')));
    }

    /** Rekomendasi daftar aplikasi dan timnya untuk autocomplete. */
    public function aplikasi(): JsonResponse
    {
        return self::json(Aplikasi::query()->orderByDesc('jumlah_issue')->orderBy('nama')
            ->get(['nama', 'team', 'jumlah_issue'])->toArray());
    }
}
