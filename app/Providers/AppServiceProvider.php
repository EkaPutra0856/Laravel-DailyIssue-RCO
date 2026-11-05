<?php

namespace App\Providers;

use App\Handover\Sources\AppsScriptSource;
use App\Handover\Sources\Source;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sumber data: Web App Apps Script read-only. Tes menggantinya dengan sumber palsu.
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
