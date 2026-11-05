<?php

use App\Http\Controllers\DailyIssueController;
use App\Http\Controllers\HandoverController;
use App\Http\Middleware\WebPassword;
use Illuminate\Support\Facades\Route;

Route::get('/healthz', [HandoverController::class, 'healthz']);

Route::middleware(WebPassword::class)->group(function () {
    Route::get('/', [DailyIssueController::class, 'page']);           // Daily Issue RCO
});
