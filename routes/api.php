<?php

use App\Http\Controllers\DailyIssueController;
use App\Http\Controllers\HandoverController;
use App\Http\Middleware\RequireJson;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

// Prefix /api dipasang di bootstrap/app.php. Tanpa sesi/cookie; POST/PUT wajib JSON (lihat RequireJson).
Route::middleware([WebPassword::class, RequireJson::class])->group(function () {
    // Rekap (dashboard rekap & Export ke Chat)
    Route::get('/config', [HandoverController::class, 'config']);
    Route::get('/issues', [HandoverController::class, 'issues']);
    Route::post('/preview', [HandoverController::class, 'preview']);
    Route::post('/send', [HandoverController::class, 'send']);

    // Daily Issue (input polisi & tindak lanjut engineer)
    Route::get('/daily/issues', [DailyIssueController::class, 'index']);
    Route::post('/daily/issues', [DailyIssueController::class, 'store']);
    Route::get('/daily/issues/{id}', [DailyIssueController::class, 'show'])->whereNumber('id');
    Route::put('/daily/issues/{id}/tindak-lanjut', [DailyIssueController::class, 'tindakLanjut'])->whereNumber('id');
    Route::get('/daily/aplikasi', [DailyIssueController::class, 'aplikasi']);
});
