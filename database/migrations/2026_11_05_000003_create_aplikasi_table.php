<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aplikasi -> team penanggung jawab, untuk rekomendasi (autofill) dan konsistensi nama aplikasi.
        Schema::create('aplikasi', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 150)->unique();
            $table->string('team', 20);
            $table->unsignedInteger('jumlah_issue')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplikasi');
    }
};
