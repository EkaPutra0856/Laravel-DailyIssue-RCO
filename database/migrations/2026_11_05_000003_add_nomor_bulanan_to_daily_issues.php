<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Nomor issue mulai dari 1 lagi tiap bulan (bulan = bulan tanggal lapor).
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->char('bulan', 7)->nullable()->after('id');           // "2026-11"
            $table->unsignedInteger('nomor')->nullable()->after('bulan'); // 1, 2, 3, ... per bulan
        });

        // Isi data yang sudah ada: urutan baris sheet untuk hasil impor, lalu urutan input untuk issue dari web.
        $rows = DB::table('daily_issues')->select('id', 'tanggal', 'sumber_baris')->get()
            ->map(fn ($r) => ['id' => $r->id, 'bulan' => substr((string) $r->tanggal, 0, 7), 'baris' => $r->sumber_baris])
            ->groupBy('bulan');
        foreach ($rows as $bulan => $items) {
            $sorted = $items->sortBy([
                fn ($a, $b) => ($a['baris'] === null) <=> ($b['baris'] === null),
                fn ($a, $b) => ($a['baris'] ?? 0) <=> ($b['baris'] ?? 0),
                fn ($a, $b) => $a['id'] <=> $b['id'],
            ])->values();
            foreach ($sorted as $i => $r) {
                DB::table('daily_issues')->where('id', $r['id'])->update(['bulan' => $bulan, 'nomor' => $i + 1]);
            }
        }

        Schema::table('daily_issues', function (Blueprint $table) {
            $table->unique(['bulan', 'nomor']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->dropUnique(['bulan', 'nomor']);
            $table->dropColumn(['bulan', 'nomor']);
        });
    }
};
