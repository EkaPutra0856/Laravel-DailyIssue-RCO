<?php

namespace App\Http\Middleware;

use App\Handover\Web\WebError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST/PUT wajib application/json, atau (untuk unggah lampiran) membawa header X-Requested-With.
 * Form lintas situs tidak bisa mengirim JSON atau header custom tanpa izin CORS, jadi ini menutup celah CSRF
 * tanpa token.
 */
class RequireJson
{
    private const MAX_JSON = 2_000_000;

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $json = str_starts_with((string) $request->header('Content-Type'), 'application/json');
            if (! $json && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
                throw new WebError('Content-Type harus application/json', 415);
            }
            if ($json && (int) $request->header('Content-Length') > self::MAX_JSON) {
                throw new WebError('Request terlalu besar', 413);
            }
        }

        return $next($request);
    }
}
