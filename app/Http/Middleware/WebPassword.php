<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Login browser (Basic Auth): username bebas, password = WEB_PASSWORD. Kosong = tanpa login. */
class WebPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('handover.web.password');
        if ($password === '' || hash_equals($password, (string) $request->getPassword())) {
            return $next($request);
        }

        return response('', 401, ['WWW-Authenticate' => 'Basic realm="Handover RCO", charset="UTF-8"']);
    }
}
