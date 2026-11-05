<?php

namespace Tests\Feature;

use App\Handover\Bot\HandoverBot;
use App\Handover\Sources\Source;
use App\Handover\Telegram;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\SampleWorkbook;
use Tests\TestCase;

class BotTest extends TestCase
{
    private const CHAT = -5023011113;

    protected function setUp(): void
    {
        parent::setUp();
        config(['handover.allowed_chat_ids' => [self::CHAT]]);
        $this->app->instance(Source::class, SampleWorkbook::source());
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
    }

    private function send(string $text, int $chat = self::CHAT, ?string $username = 'HandoverRcoBot'): array
    {
        (new HandoverBot(new Telegram('123456:TEST')))
            ->handleUpdate(['update_id' => 1, 'message' => ['chat' => ['id' => $chat], 'text' => $text]], $username);

        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))
            ->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    public function test_rekap_sends_html(): void
    {
        $sent = $this->send('/rekap Oktober 2026');
        $this->assertCount(1, $sent);
        $this->assertStringStartsWith('<b>Handover Polisi – Team RCO</b>', $sent[0]['text']);
        $this->assertSame('HTML', $sent[0]['parse_mode']);
        $this->assertSame(self::CHAT, $sent[0]['chat_id']);
    }

    public function test_doneprogress_two_messages(): void
    {
        $this->assertCount(2, $this->send('/doneprogress Oktober 2026'));
    }

    public function test_bad_period_replies_error(): void
    {
        $this->assertStringStartsWith('❌', $this->send('/rekap 40/10/2026')[0]['text']);
    }

    public function test_whitelist_blocks_other_chats(): void
    {
        $sent = $this->send('/rekap Oktober 2026', chat: 999);
        $this->assertStringContainsString('belum diizinkan', $sent[0]['text']);
    }

    public function test_help_id_and_ignored_messages(): void
    {
        $this->assertStringContainsString('Panduan Bot Handover', $this->send('/help')[0]['text']);
        $this->assertStringContainsString('<code>999</code>', $this->send('/id', chat: 999)[1]['text']);
        $this->assertCount(2, $this->send('halo semua'));                 // bukan perintah: diam
        $this->assertCount(2, $this->send('/rekap@BotLain Oktober 2026')); // perintah untuk bot lain: diam
        $this->assertCount(2, $this->send('/foo'));                        // perintah tak dikenal: diam
        $this->assertCount(3, $this->send('/rekap@HandoverRcoBot Oktober 2026'));
    }
}
