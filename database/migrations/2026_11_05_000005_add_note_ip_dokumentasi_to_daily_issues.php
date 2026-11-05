<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kolom engineer tambahan (sama dengan kolom Note, Aplikasi (IP), Dokumentasi di spreadsheet).
    // Tidak pernah ikut pesan Telegram supaya catatan teknis / IP tidak bocor ke grup.
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->text('note')->nullable()->after('waktu_selesai');
            $table->string('aplikasi_ip', 255)->nullable()->after('note');
            $table->text('dokumentasi')->nullable()->after('aplikasi_ip');
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->dropColumn(['note', 'aplikasi_ip', 'dokumentasi']);
        });
    }
};
