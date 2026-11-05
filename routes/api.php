<?php

use App\Http\Controllers\HandoverController;
use App\Http\Middleware\RequireJson;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

// Prefix /api dipasang di bootstrap/app.php. Tanpa sesi/cookie; POST wajib JSON (lihat RequireJson).
Route::middleware([WebPassword::class, RequireJson::class])->group(function () {
    Route::get('/config', [HandoverController::class, 'config']);
    Route::get('/issues', [HandoverController::class, 'issues']);
    Route::post('/preview', [HandoverController::class, 'preview']);
    Route::post('/send', [HandoverController::class, 'send']);
});
