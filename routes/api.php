<?php

use App\Http\Controllers\DailyIssueController;
use App\Http\Controllers\HandoverController;
use App\Http\Middleware\PortalLogin;
use App\Http\Middleware\RequireJson;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

// Prefix /api dipasang di bootstrap/app.php. Memakai sesi login portal (lihat PortalLogin); POST/PUT wajib JSON (lihat RequireJson).
Route::middleware([WebPassword::class, PortalLogin::class, RequireJson::class])->group(function () {
    // Kirim rekap ke Telegram (popup polisi di halaman Daily Issue)
    Route::get('/config', [HandoverController::class, 'config']);
    Route::post('/preview', [HandoverController::class, 'preview']);
    Route::post('/send', [HandoverController::class, 'send']);

    // Daily Issue (input polisi & tindak lanjut engineer)
    Route::get('/daily/issues', [DailyIssueController::class, 'index']);
    Route::get('/daily/follow-ups', [DailyIssueController::class, 'followUps']);
    Route::post('/daily/issues', [DailyIssueController::class, 'store']);
    Route::get('/daily/issues/{id}', [DailyIssueController::class, 'show'])->whereNumber('id');
    Route::put('/daily/issues/{id}/follow-up', [DailyIssueController::class, 'setFollowUp'])->whereNumber('id');
    // POST (bukan PUT) supaya bisa membawa lampiran: PHP tidak membaca multipart pada PUT.
    Route::post('/daily/issues/{id}', [DailyIssueController::class, 'update'])->whereNumber('id');
    Route::delete('/daily/issues/{id}', [DailyIssueController::class, 'destroy'])->whereNumber('id');
    Route::get('/daily/export', [DailyIssueController::class, 'export']);
    Route::put('/daily/issues/{id}/tindak-lanjut', [DailyIssueController::class, 'tindakLanjut'])->whereNumber('id');
    Route::get('/daily/aplikasi', [DailyIssueController::class, 'aplikasi']);
    Route::get('/daily/lampiran/{id}', [DailyIssueController::class, 'lampiran'])->whereNumber('id');
});
