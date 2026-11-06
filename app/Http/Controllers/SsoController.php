<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Masuk dari web portal RCO: portal yang sudah login mengirim pengguna ke
 *   /sso?data=<base64 JSON {username, nama, exp}>&sig=<HMAC-SHA256 dari data>
 * Tanda tangan dibuat dengan PORTAL_SSO_SECRET yang sama di kedua aplikasi.
 */
class SsoController extends Controller
{
    private const MAX_UMUR_DETIK = 300;  // exp tidak boleh terlalu jauh ke depan

    public function login(Request $request): RedirectResponse
    {
        $secret = (string) config('handover.web.sso_secret');
        $data = (string) $request->query('data');
        $sig = (string) $request->query('sig');
        abort_if($secret === '' || $data === '', 403, 'Buka fitur ini lewat web portal RCO.');
        abort_unless(hash_equals(hash_hmac('sha256', $data, $secret), $sig), 403, 'Tautan tidak valid.');

        $user = json_decode((string) base64_decode($data, true), true);
        $exp = (int) ($user['exp'] ?? 0);
        abort_unless(is_array($user) && ($user['username'] ?? '') !== '', 403, 'Tautan tidak valid.');
        abort_unless($exp >= time() && $exp <= time() + self::MAX_UMUR_DETIK, 403, 'Tautan kedaluwarsa. Buka lagi dari web portal RCO.');

        $request->session()->regenerate();
        $request->session()->put('portal_user', [
            'username' => mb_substr((string) $user['username'], 0, 100),
            'nama' => mb_substr((string) ($user['nama'] ?? $user['username']), 0, 100),
        ]);

        return redirect($request->boolean('embed') ? '/?embed=1' : '/');
    }
}
