<?php

use App\Http\Controllers\HandoverController;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HandoverController::class, 'healthz']);

Route::middleware(WebPassword::class)->group(function () {
    Route::get('/', [HandoverController::class, 'dashboard']);
});
