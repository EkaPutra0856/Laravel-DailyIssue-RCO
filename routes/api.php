<?php

use App\Http\Controllers\Daily\AplikasiController;
use App\Http\Controllers\Daily\ExportController;
use App\Http\Controllers\Daily\FollowUpController;
use App\Http\Controllers\Daily\IssueController;
use App\Http\Controllers\Daily\LampiranController;
use App\Http\Controllers\Daily\TindakLanjutController;
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
        // Laporan polisi
        Route::controller(IssueController::class)->group(function () {
            Route::get('/issues', 'index');
            Route::post('/issues', 'store');
            Route::get('/issues/{issue}', 'show');
            // POST (bukan PUT) supaya bisa membawa lampiran: PHP tidak membaca multipart pada PUT.
            Route::post('/issues/{issue}', 'update');
            Route::delete('/issues/{issue}', 'destroy');
        });

        // Tindak lanjut engineer
        Route::put('/issues/{issue}/tindak-lanjut', [TindakLanjutController::class, 'update']);

        // Panel Issue Follow-up
        Route::get('/follow-ups', [FollowUpController::class, 'index']);
        Route::put('/issues/{issue}/follow-up', [FollowUpController::class, 'update']);

        // Lampiran, rekomendasi aplikasi, dan unduh Excel
        Route::get('/lampiran/{lampiran}', [LampiranController::class, 'show']);
        Route::get('/aplikasi', [AplikasiController::class, 'index']);
        Route::get('/export', ExportController::class);
    });
});
