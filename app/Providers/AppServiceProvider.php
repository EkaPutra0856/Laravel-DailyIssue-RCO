<?php

namespace App\Providers;

use App\Handover\DbIssueProvider;
use App\Handover\IssueProvider;
use App\Handover\Sources\AppsScriptSource;
use App\Handover\Sources\Source;
use App\Handover\Web\WebError;
use App\Models\DailyIssue;
use App\Models\Lampiran;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rekap (dashboard, bot, CLI) membaca database.
        $this->app->bind(IssueProvider::class, DbIssueProvider::class);

        // Spreadsheet asli (hanya dibaca, lewat Web App Apps Script): dipakai untuk impor issue lama.
        $this->app->bind(Source::class, fn () => new AppsScriptSource(
            (string) config('handover.apps_script_url'),
            (string) config('handover.apps_script_key'),
        ));
    }

    public function boot(): void
    {
        // {issue} dan {lampiran} di route: hanya angka, dan langsung menjadi model-nya.
        Route::patterns(['issue' => '[0-9]+', 'lampiran' => '[0-9]+']);
        Route::bind('issue', fn (string $id) => DailyIssue::find($id)
            ?? throw new WebError("Issue #{$id} tidak ditemukan", 404));
        Route::bind('lampiran', fn (string $id) => Lampiran::find($id)
            ?? throw new WebError('Lampiran tidak ditemukan', 404));
    }
}
