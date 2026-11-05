<?php

namespace App\Http\Controllers;

use App\Handover\Teams;
use App\Handover\Web\HandoverWeb;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Daily Issue RCO:
 *   GET  /                                       halaman (?role=polisi|engineer&nama=..., ?issue=ID, ?embed=1)
 *   GET  /api/daily/issues?start=&end=            daftar issue periode itu
 *   GET  /api/daily/issues/{id}                   satu issue
 *   POST /api/daily/issues                        polisi mencatat issue baru (status otomatis pending)
 *   PUT  /api/daily/issues/{id}/tindak-lanjut     engineer mengisi status, PIC, waktu mulai/selesai
 *   GET  /api/daily/aplikasi                      daftar aplikasi -> team (rekomendasi autofill)
 */
class DailyIssueController extends Controller
{
    private const MESSAGES = [
        'required' => ':attribute wajib diisi.',
        'max' => ':attribute maksimal :max karakter.',
        'in' => ':attribute tidak valid.',
        'date_format' => 'Format :attribute tidak valid.',
        'date' => 'Format :attribute tidak valid.',
        'status.in' => 'Status harus On Progress, Trouble, atau Done.',
    ];
    private const ATTRIBUTES = [
        'issue' => 'Issue', 'aplikasi' => 'Aplikasi', 'team' => 'Team', 'pelapor' => 'Pelapor',
        'media_laporan' => 'Media laporan', 'penerima_laporan' => 'Penerima laporan', 'pencatat_issue' => 'Pencatat issue',
        'waktu_lapor' => 'Waktu lapor', 'status' => 'Status', 'pic' => 'PIC', 'waktu_mulai' => 'Waktu mulai',
        'tanggal_finish' => 'Tanggal finish', 'waktu_selesai' => 'Waktu selesai',
    ];

    public function page(): View
    {
        return view('daily', [
            'teams' => Teams::ALL,
            'statuses' => DailyIssue::STATUS_LABEL,
            'engineerStatuses' => DailyIssue::ENGINEER_STATUSES,
            'media' => DailyIssue::MEDIA,
            'portalUrl' => config('handover.web.portal_url'),
        ]);
    }

    private static function json(mixed $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }

    /** Validasi; gagal -> 422 {"error": pesan pertama, "errors": {kolom: [pesan]}}. */
    private static function validate(array $data, array $rules): array|JsonResponse
    {
        $v = Validator::make($data, $rules, self::MESSAGES, self::ATTRIBUTES);
        if ($v->fails()) {
            return self::json(['error' => $v->errors()->first(), 'errors' => $v->errors()->toArray()], 422);
        }

        return $v->validated();
    }

    private static function body(Request $request): array
    {
        $body = json_decode($request->getContent() ?: '{}', true);

        return is_array($body) ? array_map(fn ($v) => is_string($v) ? trim($v) : $v, $body) : [];
    }

    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::now(config('handover.timezone'))->toDateString();
        [$s, $e] = HandoverWeb::parseRange($request->query('start', $today), $request->query('end', $today));
        $rows = DailyIssue::query()
            ->whereBetween('tanggal', [$s->toDateString(), $e->toDateString()])
            ->orderByDesc('tanggal')->orderByDesc('waktu_lapor')->orderByDesc('id')
            ->get();

        return self::json([
            'start' => $s->toDateString(),
            'end' => $e->toDateString(),
            'fetched_at' => CarbonImmutable::now(config('handover.timezone'))->format('Y-m-d\TH:i:sP'),
            'issues' => $rows->map(fn (DailyIssue $d) => $d->toRow())->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $issue = DailyIssue::find($id);

        return $issue ? self::json($issue->toRow()) : self::json(['error' => "Issue #{$id} tidak ditemukan"], 404);
    }

    /** Polisi mencatat issue baru. Status selalu pending; bagian engineer kosong. */
    public function store(Request $request): JsonResponse
    {
        $data = self::validate(self::body($request), [
            'issue' => 'required|string|max:5000',
            'aplikasi' => 'required|string|max:150',
            'team' => ['required', Rule::in(Teams::ALL)],
            'pelapor' => 'required|string|max:150',
            'media_laporan' => 'required|string|max:100',
            'penerima_laporan' => 'required|string|max:150',
            'pencatat_issue' => 'required|string|max:100',
            'waktu_lapor' => 'required|date_format:Y-m-d\TH:i',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $lapor = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $data['waktu_lapor']);
        $data['aplikasi'] = trim(preg_replace('/\s+/u', ' ', $data['aplikasi']));
        // Pakai ejaan nama aplikasi yang sudah terdaftar supaya konsisten (mis. "rekon atm" -> "Rekon ATM").
        if ($known = Aplikasi::findByNama($data['aplikasi'])) {
            $data['aplikasi'] = $known->nama;
        }

        $issue = DB::transaction(function () use ($data, $lapor) {
            $issue = DailyIssue::create([
                ...$data,
                'tanggal' => $lapor->toDateString(),
                'waktu_lapor' => $lapor->format('H:i:00'),
                'status' => 'pending',
                'pic' => '',
            ]);
            Aplikasi::catat($data['aplikasi'], $data['team']);

            return $issue;
        });

        return self::json($issue->fresh()->toRow(), 201);
    }

    /** Engineer menindaklanjuti issue. Wajib PIC dan status On Progress / Trouble / Done. */
    public function tindakLanjut(Request $request, int $id): JsonResponse
    {
        $issue = DailyIssue::find($id);
        if (! $issue) {
            return self::json(['error' => "Issue #{$id} tidak ditemukan"], 404);
        }
        $data = self::validate(self::body($request), [
            'status' => ['required', Rule::in(DailyIssue::ENGINEER_STATUSES)],
            'pic' => 'required|string|max:100',
            'waktu_mulai' => 'nullable|date_format:H:i',
            'tanggal_finish' => 'nullable|date_format:Y-m-d',
            'waktu_selesai' => 'nullable|date_format:H:i',
        ]);
        if ($data instanceof JsonResponse) {
            return $data;
        }
        $issue->update([
            'status' => $data['status'],
            'pic' => $data['pic'],
            'waktu_mulai' => ($data['waktu_mulai'] ?? null) ?: null,
            'tanggal_finish' => ($data['tanggal_finish'] ?? null) ?: null,
            'waktu_selesai' => ($data['waktu_selesai'] ?? null) ?: null,
        ]);

        return self::json($issue->fresh()->toRow());
    }

    public function aplikasi(): JsonResponse
    {
        return self::json(Aplikasi::query()->orderByDesc('jumlah_issue')->orderBy('nama')
            ->get(['nama', 'team', 'jumlah_issue'])->toArray());
    }
}
