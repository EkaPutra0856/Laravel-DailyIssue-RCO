<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Hanya pengguna yang masuk lewat web portal RCO (lihat SsoController) yang boleh mengakses.
 * PORTAL_SSO_SECRET kosong = pemeriksaan mati (untuk pengembangan lokal).
 */
class PortalLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ((string) config('handover.web.sso_secret') === '' || $request->session()->has('portal_user')) {
            return $next($request);
        }
        if ($request->is('api/*')) {
            return response()->json(['error' => 'Sesi berakhir. Buka lagi dari web portal RCO.'], 401, [], JSON_UNESCAPED_UNICODE);
        }
        $portal = (string) config('handover.web.portal_url');

        return $portal !== '' ? redirect()->away($portal) : response('Buka fitur ini lewat web portal RCO.', 403);
    }
}
