<?php

namespace App\Http\Controllers;

use App\Handover\Teams;
use App\Handover\Web\HandoverWeb;
use App\Models\Aplikasi;
use App\Models\DailyIssue;
use App\Models\Lampiran;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Daily Issue RCO:
 *   GET  /                                       halaman (?role=polisi|engineer&nama=..., ?issue=ID, ?embed=1)
 *   GET  /api/daily/issues?start=&end=            daftar issue periode itu
 *   GET  /api/daily/issues/{id}                   satu issue
 *   POST /api/daily/issues                        polisi mencatat issue baru (status otomatis pending),
 *                                                 JSON, atau multipart dengan lampiran[] (gambar/file)
 *   PUT  /api/daily/issues/{id}/tindak-lanjut     engineer mengisi status, PIC, waktu mulai/selesai
 *   GET  /api/daily/lampiran/{id}                 tampilkan gambar / unduh file lampiran
 *   GET  /api/daily/aplikasi                      daftar aplikasi -> team (rekomendasi autofill)
 */
class DailyIssueController extends Controller
{
    public const MAX_LAMPIRAN = 10;
    public const MAX_KB = 10240;  // 10 MB per file
    public const EKSTENSI = 'jpg,jpeg,png,gif,webp,bmp,pdf,doc,docx,xls,xlsx,csv,ppt,pptx,txt,log,zip,rar,7z';

    private const MESSAGES = [
        'required' => ':attribute wajib diisi.',
        'max' => ':attribute maksimal :max karakter.',
        'in' => ':attribute tidak valid.',
        'date_format' => 'Format :attribute tidak valid.',
        'date' => 'Format :attribute tidak valid.',
        'status.in' => 'Status harus On Progress, Trouble, atau Done.',
        'lampiran.max' => 'Maksimal 10 lampiran per issue.',
        'lampiran.*.max' => 'Ukuran tiap lampiran maksimal 10 MB.',
        'lampiran.*.mimes' => 'Jenis file lampiran tidak didukung (gambar, PDF, Word/Excel/PowerPoint, TXT/CSV, ZIP).',
        'lampiran.*.uploaded' => 'Lampiran gagal diunggah (mungkin terlalu besar).',
        'lampiran.*.file' => 'Lampiran gagal diunggah.',
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
            'mediaDefault' => DailyIssue::MEDIA_DEFAULT,
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

    /** Isian dari JSON, atau dari form multipart (popup polisi yang membawa lampiran). */
    private static function body(Request $request): array
    {
        $body = $request->isJson()
            ? json_decode($request->getContent() ?: '{}', true)
            : $request->except('lampiran');

        return is_array($body) ? array_map(fn ($v) => is_string($v) ? trim($v) : $v, $body) : [];
    }

    public function index(Request $request): JsonResponse
    {
        $today = CarbonImmutable::now(config('handover.timezone'))->toDateString();
        [$s, $e] = HandoverWeb::parseRange($request->query('start', $today), $request->query('end', $today));
        $rows = DailyIssue::query()
            ->whereBetween('tanggal', [$s->toDateString(), $e->toDateString()])
            ->orderByDesc('tanggal')->orderByDesc('waktu_lapor')->orderByDesc('id')
            ->with('lampiran')
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
        $issue = DailyIssue::with('lampiran')->find($id);

        return $issue ? self::json($issue->toRow()) : self::json(['error' => "Issue #{$id} tidak ditemukan"], 404);
    }

    /** Polisi mencatat issue baru (boleh dengan lampiran). Status selalu pending; bagian engineer kosong. */
    public function store(Request $request): JsonResponse
    {
        $files = array_values(array_filter((array) $request->file('lampiran', [])));
        $data = self::validate([...self::body($request), 'lampiran' => $files], [
            'lampiran' => 'array|max:'.self::MAX_LAMPIRAN,
            'lampiran.*' => 'file|max:'.self::MAX_KB.'|mimes:'.self::EKSTENSI,
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

        unset($data['lampiran']);

        $stored = [];
        try {
            $issue = DB::transaction(function () use ($data, $lapor, $files, &$stored) {
                $issue = DailyIssue::create([
                    ...$data,
                    'tanggal' => $lapor->toDateString(),
                    'waktu_lapor' => $lapor->format('H:i:00'),
                    'status' => 'pending',
                    'pic' => '',
                ]);
                Aplikasi::catat($data['aplikasi'], $data['team']);
                foreach ($files as $file) {
                    // Nama file di disk acak; nama asli hanya disimpan di database.
                    $stored[] = $path = $file->store('lampiran/'.$lapor->format('Y/m'), 'local');
                    $issue->lampiran()->create([
                        'nama' => mb_substr($file->getClientOriginalName() ?: 'lampiran', 0, 255),
                        'path' => $path,
                        'mime' => $file->getMimeType() ?: 'application/octet-stream',
                        'ukuran' => $file->getSize(),
                    ]);
                }

                return $issue;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($stored);  // jangan tinggalkan file tanpa issue

            throw $e;
        }

        return self::json($issue->fresh()->load('lampiran')->toRow(), 201);
    }

    /** Gambar ditampilkan langsung; file lain selalu diunduh (tidak dibuka di browser). */
    public function lampiran(int $id): Response|JsonResponse
    {
        $l = Lampiran::find($id);
        if (! $l || ! Storage::disk('local')->exists($l->path)) {
            return self::json(['error' => 'Lampiran tidak ditemukan'], 404);
        }

        return Storage::disk('local')->response($l->path, $l->nama, [
            'Content-Type' => $l->isGambar() ? $l->mime : 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
        ], $l->isGambar() ? 'inline' : 'attachment');
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

        return self::json($issue->fresh()->load('lampiran')->toRow());
    }

    public function aplikasi(): JsonResponse
    {
        return self::json(Aplikasi::query()->orderByDesc('jumlah_issue')->orderBy('nama')
            ->get(['nama', 'team', 'jumlah_issue'])->toArray());
    }
}
