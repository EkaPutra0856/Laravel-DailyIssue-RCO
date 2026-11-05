<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya script & css dari server sendiri yang boleh jalan. Halaman hanya boleh ditampilkan di iframe oleh
 * server sendiri dan web portal yang terdaftar di PORTAL_ORIGIN.
 */
class SecurityHeaders
{
    private const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; "
        ."connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Origin dari .env, buang karakter yang bisa menyisipkan direktif CSP lain.
        $origins = array_map(fn ($o) => preg_replace('/[^A-Za-z0-9:\/.\-*]/', '', $o), config('handover.web.portal_origins', []));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', trim(self::CSP." 'self' ".implode(' ', $origins)));

        return $response;
    }
}
