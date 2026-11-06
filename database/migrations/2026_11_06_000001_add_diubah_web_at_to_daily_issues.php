<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Kapan issue terakhir diubah lewat web. Sinkron dari spreadsheet tidak menimpa issue yang kolom ini terisi.
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->timestamp('diubah_web_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->dropColumn('diubah_web_at');
        });
    }
};
