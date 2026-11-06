<?php

use App\Http\Controllers\DailyIssueController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\SsoController;
use App\Http\Middleware\PortalLogin;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HandoverController::class, 'healthz']);
Route::get('/sso', [SsoController::class, 'login']);  // masuk dari web portal RCO

Route::middleware([WebPassword::class, PortalLogin::class])->group(function () {
    Route::get('/', [DailyIssueController::class, 'page']);           // Daily Issue RCO
});
