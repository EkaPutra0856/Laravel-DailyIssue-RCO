<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'kunci-tes';

    protected function setUp(): void
    {
        parent::setUp();
        config(['handover.web.password' => '', 'handover.web.sso_secret' => self::SECRET,
            'handover.web.portal_url' => 'http://172.18.66.243/home']);
    }

    private static function link(array $user, string $secret = self::SECRET): string
    {
        $data = base64_encode(json_encode($user));

        return '/sso?'.http_build_query(['data' => $data, 'sig' => hash_hmac('sha256', $data, $secret)]);
    }

    private static function user(array $o = []): array
    {
        return ['username' => 'eka', 'nama' => 'Eka Putra', 'exp' => time() + 60, ...$o];
    }

    public function test_without_portal_login_everything_is_closed(): void
    {
        $this->get('/')->assertRedirect('http://172.18.66.243/home');
        $this->getJson('/api/daily/issues')->assertStatus(401);
        $this->getJson('/api/daily/lampiran/1')->assertStatus(401);
        $this->getJson('/api/daily/export')->assertStatus(401);
        $this->postJson('/api/daily/issues', [])->assertStatus(401);
        $this->deleteJson('/api/daily/issues/1')->assertStatus(401);
        $this->get('/healthz')->assertOk();
    }

    public function test_valid_portal_link_opens_app_with_portal_name(): void
    {
        $this->get(self::link(self::user()))->assertRedirect('/');
        $this->get('/')->assertOk()->assertSee('data-portal-nama="Eka Putra"', false);
        $this->getJson('/api/daily/issues')->assertOk();
    }

    public function test_embed_is_kept(): void
    {
        $this->get(self::link(self::user()).'&embed=1')->assertRedirect('/?embed=1');
    }

    public function test_bad_links_are_rejected(): void
    {
        $this->get(self::link(self::user(), 'kunci-salah'))->assertForbidden();              // tanda tangan palsu
        $this->get(self::link(self::user(['exp' => time() - 1])))->assertForbidden();        // kedaluwarsa
        $this->get(self::link(self::user(['exp' => time() + 86400])))->assertForbidden();    // masa berlaku tidak wajar
        $this->get(self::link(self::user(['username' => ''])))->assertForbidden();
        $this->get('/sso')->assertForbidden();
        $this->get('/')->assertRedirect('http://172.18.66.243/home');                        // tetap belum masuk
    }

    public function test_empty_secret_keeps_local_development_open(): void
    {
        config(['handover.web.sso_secret' => '']);
        $this->get('/')->assertOk();
        $this->getJson('/api/daily/issues')->assertOk();
        $this->get(self::link(self::user(), ''))->assertForbidden();  // /sso tidak bisa dipakai tanpa kunci
    }
}
