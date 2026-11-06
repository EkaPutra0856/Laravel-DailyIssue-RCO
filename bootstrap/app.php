<?php

use App\Http\Middleware\PortalLogin;
use App\Http\Middleware\RequireJson;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\WebPassword;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        // API memakai sesi yang sama dengan halaman, untuk memeriksa login portal (lihat PortalLogin).
        $middleware->api(prepend: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
        ]);
        // Penjaga berjalan sebelum {issue} dicari, supaya yang belum login tidak bisa menebak id yang ada.
        foreach ([WebPassword::class, PortalLogin::class, RequireJson::class] as $penjaga) {
            $middleware->prependToPriorityList(SubstituteBindings::class, $penjaga);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error tak terduga di API: pesan umum (tanpa detail yang bisa memuat key/token), detail ke log.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') || $e instanceof \App\Handover\Web\WebError || $e instanceof HttpResponseException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }
            Log::error('Error di '.$request->method().' '.$request->path().': '.$e->getMessage());

            return response()->json(['error' => 'Terjadi error di server. Cek storage/logs/laravel.log.'], 500);
        });
    })->create();
