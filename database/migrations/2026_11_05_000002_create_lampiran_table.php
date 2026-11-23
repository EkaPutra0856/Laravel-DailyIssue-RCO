<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gambar / file yang dilampirkan polisi / engineer. Disimpan di storage disk "local".
        Schema::create('lampiran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_issue_id')->constrained('daily_issues')->cascadeOnDelete();
            $table->string('nama', 255);         // nama file asli dari pengguna
            $table->string('path', 255);         // lokasi di disk "local"
            $table->string('mime', 100);
            $table->unsignedBigInteger('ukuran'); // byte
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lampiran');
    }
};
