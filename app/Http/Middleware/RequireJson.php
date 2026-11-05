<?php

namespace App\Http\Middleware;

use App\Handover\Web\WebError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST wajib application/json: form lintas situs tidak bisa mengirim JSON tanpa izin CORS,
 * jadi ini menutup celah CSRF tanpa token (API tidak memakai sesi).
 */
class RequireJson
{
    private const MAX_BODY = 2_000_000;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST')) {
            if (! str_starts_with((string) $request->header('Content-Type'), 'application/json')) {
                throw new WebError('Content-Type harus application/json', 415);
            }
            if ((int) $request->header('Content-Length') > self::MAX_BODY) {
                throw new WebError('Request terlalu besar', 413);
            }
        }

        return $next($request);
    }
}
