<?php

namespace App\Providers;

use App\Handover\DbIssueProvider;
use App\Handover\IssueProvider;
use App\Handover\Sources\AppsScriptSource;
use App\Handover\Sources\Source;
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
        //
    }
}
