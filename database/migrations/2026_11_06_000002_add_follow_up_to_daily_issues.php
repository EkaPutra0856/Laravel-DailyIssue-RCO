<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->boolean('follow_up')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('daily_issues', function (Blueprint $table) {
            $table->dropColumn('follow_up');
        });
    }
};