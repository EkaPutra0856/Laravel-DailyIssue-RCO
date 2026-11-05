<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Issue yang dihapus polisi tidak benar-benar dibuang (bisa dipulihkan), hanya ditandai deleted_at.
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->softDeletes();
            $table->string('dihapus_oleh', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('dihapus_oleh');
        });
    }
};
