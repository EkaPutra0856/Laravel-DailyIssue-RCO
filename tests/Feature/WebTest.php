<?php

namespace Tests\Feature;

use App\Handover\IssueProvider;
use App\Handover\IssueService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

/** Dashboard rekap & Export ke Chat (logika pesan diuji dengan contoh data yang sama seperti versi Python). */
class WebTest extends TestCase
{
    private const BODY = ['start' => '2026-10-01', 'end' => '2026-10-31', 'categories' => ['pending', 'progress']];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'handover.telegram_token' => '123456:SECRET',
            'handover.allowed_chat_ids' => [-5023011113],
            'handover.web.chat_id' => '',
            'handover.web.password' => '',
            'handover.web.portal_origins' => [],
        ]);
        Cache::flush();
        $this->app->instance(IssueProvider::class, new IssueService(SampleWorkbook::source()));
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['title' => 'TesBot']]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => []]),
        ]);
    }

    private function issues(string $query = '?start=2026-10-01&end=2026-10-31')
    {
        return $this->getJson('/api/issues'.$query);
    }

    public function test_rekap_page_and_health(): void
    {
        $this->get('/rekap')->assertOk()->assertSee('static/js/app.js')
            ->assertHeader('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/healthz')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_frame_ancestors_allow_portal_only(): void
    {
        $this->get('/healthz')->assertHeader('Content-Security-Policy');
        $this->assertStringEndsWith("frame-ancestors 'self'", $this->get('/healthz')->headers->get('Content-Security-Policy'));
        config(['handover.web.portal_origins' => ['http://172.18.66.243', "evil; script-src *"]]);
        $csp = $this->get('/healthz')->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'self' http://172.18.66.243", $csp);
        $this->assertStringNotContainsString('script-src *', $csp);  // origin tidak bisa menyisipkan direktif lain
    }

    public function test_config(): void
    {
        $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('title', 'Handover Polisi – Team RCO')
            ->assertJsonPath('teams', ['Tim 1', 'Tim 2', 'Tim 3', 'ALL TEAM'])
            ->assertJsonPath('chat', ['id' => -5023011113, 'title' => 'TesBot']);
    }

    public function test_issues_payload_has_links_and_categories(): void
    {
        $data = $this->issues()->assertOk()->json();
        $by = array_column($data['issues'], null, 'issue_no');
        $this->assertSame('pending', $by[3]['category']);
        $this->assertSame('Tim 1', $by[3]['team']);
        $this->assertSame('done', $by[6]['category']);
        $this->assertStringEndsWith('/?issue=3', $by[3]['link']);  // buka issue di halaman Daily Issue
        $this->assertArrayNotHasKey(14, $by);
        $this->assertFalse($data['updating']);
    }

    public function test_bad_range(): void
    {
        $this->issues('?start=2026-10-31&end=2026-10-01')->assertStatus(400)
            ->assertJsonPath('error', 'Tanggal akhir lebih awal dari tanggal mulai');
        $this->issues('?start=x&end=y')->assertStatus(400);
    }

    public function test_preview_matches_bot_format(): void
    {
        $msgs = $this->postJson('/api/preview', self::BODY)->assertOk()->json('messages');
        $this->assertCount(1, $msgs);
        $this->assertStringStartsWith('<b>Handover Polisi – Team RCO</b>', $msgs[0]);
        $this->assertStringContainsString('Total Pending: 4 Issue (Putih)', $msgs[0]);
    }

    public function test_preview_team_filter_and_visible_keys(): void
    {
        $msg = $this->postJson('/api/preview', [...self::BODY, 'teams' => ['Tim 1']])->json('messages.0');
        $this->assertStringContainsString('<b>Tim 1</b>', $msg);
        $this->assertStringNotContainsString('<b>Tim 2</b>', $msg);
        $this->assertStringContainsString('Total Pending: 3 Issue (Putih)', $msg);

        $msg = $this->postJson('/api/preview', [...self::BODY, 'keys' => ['Issue Oktober 2026#3']])->json('messages.0');
        $this->assertStringContainsString('Issue 3', $msg);
        $this->assertStringNotContainsString('Issue 7', $msg);
        $this->assertStringContainsString('Total Pending: 1 Issue (Putih)', $msg);
    }

    public function test_preview_requires_category_and_json(): void
    {
        $this->postJson('/api/preview', [...self::BODY, 'categories' => []])->assertStatus(400);
        $this->postJson('/api/preview', [...self::BODY, 'categories' => ['x']])->assertStatus(400)
            ->assertJsonPath('error', 'Kategori tidak dikenal');
        $this->post('/api/preview', self::BODY)->assertStatus(415);  // form biasa ditolak (CSRF)
    }

    public function test_send_posts_each_message(): void
    {
        $this->postJson('/api/send', self::BODY)->assertOk()->assertJsonPath('sent', 1)
            ->assertJsonPath('chat.id', -5023011113);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === -5023011113 && $r['parse_mode'] === 'HTML'
            && str_starts_with($r['text'], '<b>Handover Polisi'));
    }

    public function test_send_error_hides_token(): void
    {
        Http::fake(['api.telegram.org/*/sendMessage' => fn () => throw new \Illuminate\Http\Client\ConnectionException('boom https://api.telegram.org/bot123456:SECRET/sendMessage')]);
        $res = $this->postJson('/api/send', self::BODY)->assertStatus(502);
        $this->assertStringNotContainsString('SECRET', $res->getContent());
        $res->assertJsonPath('error', 'Gagal menghubungi Telegram (pesan 1/1). Coba lagi.');
    }

    public function test_password(): void
    {
        config(['handover.web.password' => 'rahasia']);
        $this->get('/rekap')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->getJson('/api/config')->assertStatus(401);
        $this->get('/healthz')->assertOk();  // tanpa login
        $this->get('/rekap', ['Authorization' => 'Basic '.base64_encode('siapa:rahasia')])->assertOk();
        $this->get('/rekap', ['Authorization' => 'Basic '.base64_encode('siapa:salah')])->assertStatus(401);
    }
}
