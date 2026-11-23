<?php

use App\Http\Controllers\Daily\DailyIssueController;
use App\Http\Controllers\Daily\ExportController;
use App\Http\Controllers\Daily\LampiranController;
use App\Http\Controllers\Daily\SyncController;
use App\Http\Controllers\HandoverController;
use App\Http\Middleware\PortalLogin;
use App\Http\Middleware\RequireJson;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

// Prefix /api dipasang di bootstrap/app.php. {issue} dan {lampiran} dicari di AppServiceProvider.
Route::middleware([WebPassword::class, PortalLogin::class, RequireJson::class])->group(function () {
    // Kirim rekap ke Telegram (popup polisi)
    Route::controller(HandoverController::class)->group(function () {
        Route::get('/config', 'config');
        Route::post('/preview', 'preview');
        Route::post('/send', 'send');
    });

    Route::prefix('daily')->group(function () {
        // Siklus hidup Daily Issue (Polisi & Engineer)
        Route::controller(DailyIssueController::class)->group(function () {
            // Laporan polisi
            Route::get('/issues', 'index');
            Route::post('/issues', 'store');
            Route::get('/issues/{issue}', 'show');
            Route::post('/issues/{issue}', 'update');
            Route::delete('/issues/{issue}', 'destroy');

            // Tindak lanjut engineer
            Route::match(['PUT', 'POST'], '/issues/{issue}/tindak-lanjut', 'tindakLanjut');

            // Panel Issue Follow-up
            Route::get('/follow-ups', 'followUps');
            Route::put('/issues/{issue}/follow-up', 'toggleFollowUp');

            // Rekomendasi nama aplikasi
            Route::get('/aplikasi', 'aplikasi');
        });

        // Specialized Controllers: Lampiran, Export Excel, dan Sinkronisasi Spreadsheet
        Route::get('/lampiran/{lampiran}', [LampiranController::class, 'show']);
        Route::get('/export', ExportController::class);
        Route::post('/sync', SyncController::class);
    });
});
