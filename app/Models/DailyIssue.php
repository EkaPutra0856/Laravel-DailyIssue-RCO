<?php

namespace App\Models;

use App\Handover\Category;
use App\Handover\Dates;
use App\Handover\Issue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Satu issue harian. Polisi mengisi bagian lapor, engineer mengisi bagian penyelesaian. */
class DailyIssue extends Model
{
    use SoftDeletes;  // hapus oleh polisi = ditandai deleted_at, data tetap ada

    public const STATUSES = ['pending', 'onprogress', 'trouble', 'done'];
    // Status yang boleh dikirim engineer (pending = belum dikerjakan, bukan hasil tindak lanjut).
    public const ENGINEER_STATUSES = ['onprogress', 'trouble', 'done'];
    public const STATUS_LABEL = ['pending' => 'Pending', 'onprogress' => 'On Progress', 'trouble' => 'Trouble', 'done' => 'Done'];
    public const MEDIA = ['WhatsApp', 'Personal Chat', 'Email', 'Telepon'];  // saran; boleh diketik manual
    public const MEDIA_DEFAULT = 'WhatsApp';

    protected $table = 'daily_issues';

    protected $fillable = [
        'tanggal', 'waktu_lapor', 'issue', 'aplikasi', 'team', 'pelapor', 'media_laporan', 'penerima_laporan',
        'pencatat_issue', 'status', 'pic', 'waktu_mulai', 'tanggal_finish', 'waktu_selesai', 'sumber_tab', 'sumber_baris',
    ];

    protected $attributes = ['status' => 'pending', 'pic' => ''];

    protected function casts(): array
    {
        return ['tanggal' => 'date:Y-m-d', 'tanggal_finish' => 'date:Y-m-d'];
    }

    /**
     * Nomor issue mulai dari 1 lagi tiap bulan. Issue baru (dan issue yang tanggal lapornya dipindah ke bulan lain)
     * mendapat nomor berikutnya di bulan itu. Simpan di dalam DB::transaction supaya nomor tidak bentrok.
     */
    protected static function booted(): void
    {
        static::saving(function (DailyIssue $issue) {
            $bulan = $issue->tanggal?->format('Y-m');
            if ($bulan && ($issue->nomor === null || $issue->bulan !== $bulan)) {
                $issue->bulan = $bulan;
                $issue->nomor = (int) self::withTrashed()->where('bulan', $bulan)->lockForUpdate()->max('nomor') + 1;
            }
        });
    }

    private static function hhmm(?string $time): ?string
    {
        return $time ? substr($time, 0, 5) : null;
    }

    /** Bentuk JSON untuk halaman Daily Issue. */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'nomor' => $this->nomor,     // nomor issue di bulannya (mulai 1 tiap bulan)
            'bulan' => $this->bulan,
            'tanggal' => $this->tanggal?->toDateString(),
            'waktu_lapor' => self::hhmm($this->waktu_lapor),
            'issue' => $this->issue,
            'aplikasi' => $this->aplikasi,
            'team' => $this->team,
            'pelapor' => $this->pelapor,
            'media_laporan' => $this->media_laporan,
            'penerima_laporan' => $this->penerima_laporan,
            'pencatat_issue' => $this->pencatat_issue,
            'status' => $this->status,
            'pic' => $this->pic,
            'waktu_mulai' => self::hhmm($this->waktu_mulai),
            'tanggal_finish' => $this->tanggal_finish?->toDateString(),
            'waktu_selesai' => self::hhmm($this->waktu_selesai),
            'sumber' => $this->sumber_tab ? "{$this->sumber_tab} baris {$this->sumber_baris}" : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'lampiran' => $this->lampiran->map(fn (Lampiran $l) => $l->toRow())->all(),
        ];
    }

    public function lampiran(): HasMany
    {
        return $this->hasMany(Lampiran::class)->orderBy('id');
    }

    /** Ubah ke Issue rekap (dipakai dashboard rekap & bot). Trouble dihitung on progress dengan tanda khusus. */
    public function toIssue(): Issue
    {
        $category = match ($this->status) {
            'done' => Category::DONE,
            'onprogress', 'trouble' => Category::PROGRESS,
            default => Category::PENDING,
        };

        return new Issue(
            sheet: (string) $this->bulan,  // kunci unik "2026-11#5" walau rentang rekap lintas bulan
            issueNo: (int) $this->nomor,
            tanggal: Dates::make($this->tanggal->year, $this->tanggal->month, $this->tanggal->day),
            team: $this->team,
            aplikasi: $this->aplikasi,
            status: self::STATUS_LABEL[$this->status] ?? $this->status,
            pic: $this->pic,
            category: $category,
            doneReason: $category === Category::DONE ? 'status' : '',
            trouble: $this->status === 'trouble',
            ref: $this->id,
        );
    }
}
