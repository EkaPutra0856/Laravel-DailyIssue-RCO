<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Isi kolom IP di sheet sering beberapa baris (IP, nama DB, ...) dan bisa lebih dari 255 karakter.
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->text('aplikasi_ip')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->string('aplikasi_ip', 255)->nullable()->change();
        });
    }
};
