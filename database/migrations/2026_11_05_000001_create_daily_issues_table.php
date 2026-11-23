<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris = satu issue (setara satu baris di tab "Issue <Bulan> <Tahun>" spreadsheet).
        Schema::create('daily_issues', function (Blueprint $table) {
            $table->id();

            // Nomor urut bulanan (reset ke 1 setiap awal bulan)
            $table->char('bulan', 7)->nullable()->index(); // mis. '2026-11'
            $table->unsignedInteger('nomor')->nullable();
            $table->unique(['bulan', 'nomor']);

            // Diisi polisi (pencatat issue)
            $table->date('tanggal')->index();
            $table->time('waktu_lapor')->nullable();
            $table->text('issue');
            $table->string('aplikasi', 150)->default('')->index();
            $table->string('team', 20)->default('ALL TEAM')->index();  // Tim 1 / Tim 2 / Tim 3 / ALL TEAM
            $table->string('pelapor', 150)->default('');
            $table->string('media_laporan', 100)->default('');
            $table->string('penerima_laporan', 150)->default('');
            $table->string('pencatat_issue', 100)->default('');

            // Diisi engineer (penyelesai issue)
            $table->string('status', 20)->default('pending')->index();  // pending / onprogress / trouble / other / done
            $table->boolean('follow_up')->default(false)->index();
            $table->string('pic', 100)->default('');
            $table->time('waktu_mulai')->nullable();
            $table->date('tanggal_finish')->nullable();
            $table->time('waktu_selesai')->nullable();
            $table->text('note')->nullable();
            $table->string('aplikasi_ip', 150)->nullable();
            $table->string('dokumentasi', 255)->nullable();

            // Asal data hasil impor dari spreadsheet (null untuk issue yang diinput di web)
            $table->string('sumber_tab', 100)->nullable();
            $table->unsignedInteger('sumber_baris')->nullable();
            $table->unique(['sumber_tab', 'sumber_baris']);
            $table->timestamp('diubah_web_at')->nullable()->index();

            $table->timestamps();
            $table->string('dihapus_oleh', 100)->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_issues');
    }
};
