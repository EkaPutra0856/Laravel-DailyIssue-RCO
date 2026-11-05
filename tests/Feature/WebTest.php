<?php

namespace Tests\Feature;

use App\Handover\Sources\Source;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\ArraySource;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

class WebTest extends TestCase
{
    private const ASLI = 'https://docs.google.com/spreadsheets/d/1lmIgK_GMZaeRUUc0Ea_K31jpRAn_P_PifWsm5PvjJsw/edit';
    private const BODY = ['start' => '2026-10-01', 'end' => '2026-10-31', 'categories' => ['pending', 'progress']];

    private ArraySource $source;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'handover.telegram_token' => '123456:SECRET',
            'handover.allowed_chat_ids' => [-5023011113],
            'handover.web.chat_id' => '',
            'handover.web.password' => '',
            'handover.web.link_target' => 'asli',
            'handover.spreadsheet_id' => '1lmIgK_GMZaeRUUc0Ea_K31jpRAn_P_PifWsm5PvjJsw',
        ]);
        Cache::flush();
        $this->source = SampleWorkbook::source(['Issue Oktober 2026' => '777']);
        $this->app->instance(Source::class, $this->source);
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

    public function test_dashboard_and_health(): void
    {
        $this->get('/')->assertOk()->assertSee('static/js/app.js')
            ->assertHeader('Content-Security-Policy')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/healthz')->assertOk()->assertExactJson(['ok' => true]);
    }

    public function test_config(): void
    {
        $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('title', 'Handover Polisi – Team RCO')
            ->assertJsonPath('teams', ['Tim 1', 'Tim 2', 'Tim 3', 'ALL TEAM'])
            ->assertJsonPath('chat', ['id' => -5023011113, 'title' => 'TesBot'])
            ->assertJsonPath('sheet_url', self::ASLI);
    }

    public function test_issues_payload_has_links_and_categories(): void
    {
        $data = $this->issues()->assertOk()->json();
        $by = array_column($data['issues'], null, 'issue_no');
        $this->assertSame('pending', $by[3]['category']);
        $this->assertSame('Tim 1', $by[3]['team']);
        $this->assertSame('done', $by[6]['category']);
        $this->assertSame(self::ASLI.'#gid=777&range=A3', $by[3]['link']);  // langsung ke baris di sheet asli
        $this->assertSame('Issue Oktober 2026#3', $by[3]['key']);
        $this->assertArrayNotHasKey(14, $by);
        $this->assertFalse($data['updating']);
    }

    public function test_links_to_transit_when_configured(): void
    {
        config(['handover.web.link_target' => 'transit']);
        Http::fake(['docs.google.com/*' => Http::response(
            'items.push({name: "Issue Oktober 2026", pageUrl: "https:\/\/x\/htmlview\/sheet?headers\x3dtrue&gid=352", gid: "352",initialSheet: ("352" == gid)});')]);
        $by = array_column($this->issues()->json('issues'), null, 'issue_no');
        $this->assertStringContainsString('/d/1UK5iVxAi8ROiUHGIjGeMLeMZAOUAh-nRRb75eb3F59A/', $by[3]['link']);
        $this->assertStringEndsWith('#gid=352&range=A3', $by[3]['link']);
    }

    public function test_bad_range(): void
    {
        $this->issues('?start=2026-10-31&end=2026-10-01')->assertStatus(400)
            ->assertJsonPath('error', 'Tanggal akhir lebih awal dari tanggal mulai');
        $this->issues('?start=x&end=y')->assertStatus(400);
    }

    public function test_cache_stale_then_refresh(): void
    {
        $first = $this->issues()->json();
        $this->assertSame(1, $this->source->fetches);
        $this->issues();  // < 60 detik: cache
        $this->assertSame(1, $this->source->fetches);

        $this->travel(2)->minutes();  // lewat 60 detik, masih < 15 menit
        $stale = $this->issues()->json();
        $this->assertTrue($stale['updating']);  // data lama langsung tampil, browser ambil ulang dengan refresh=1
        $this->assertSame($first['fetched_at'], $stale['fetched_at']);
        $this->assertSame(1, $this->source->fetches);

        $fresh = $this->issues('?start=2026-10-01&end=2026-10-31&refresh=1')->json();
        $this->assertFalse($fresh['updating']);
        $this->assertSame(2, $this->source->fetches);

        $this->travel(2)->minutes();
        $this->postJson('/api/preview', self::BODY)->assertOk();  // pesan Telegram selalu data segar
        $this->assertSame(3, $this->source->fetches);

        $this->travel(20)->minutes();  // lebih tua dari 15 menit: ambil ulang langsung
        $this->assertFalse($this->issues()->json('updating'));
        $this->assertSame(4, $this->source->fetches);
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
        $this->assertStringContainsString('Total On Progress: 0 Issue (Kuning)', $msg);
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
        $this->get('/')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->getJson('/api/config')->assertStatus(401);
        $this->get('/healthz')->assertOk();  // tanpa login
        $this->get('/', ['Authorization' => 'Basic '.base64_encode('siapa:rahasia')])->assertOk();
        $this->get('/', ['Authorization' => 'Basic '.base64_encode('siapa:salah')])->assertStatus(401);
    }
}
