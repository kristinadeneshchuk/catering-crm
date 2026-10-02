<?php

namespace Tests\Feature;

use App\Services\TelegramService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 28.09 тижневий дайджест не дійшов: частина «нестандартні оплати» мала
 * 8 049 символів, а Telegram відхиляє все, що довше 4096.
 */
class TelegramLongMessageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram.bot_token', 'test-token');
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
    }

    private function longReport(int $lines = 200): string
    {
        return implode("\n", array_map(fn ($i) => "• <b>Оплата {$i}</b>: 1 450 ₴ — переказ на картку, клієнт #{$i}", range(1, $lines)));
    }

    public function test_a_long_message_is_sent_in_parts_under_the_limit(): void
    {
        $text = $this->longReport();
        $this->assertGreaterThan(4096, mb_strlen($text));

        app(TelegramService::class)->send('1', $text);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]['text']);
        $this->assertGreaterThan(1, $sent->count());
        $sent->each(fn ($part) => $this->assertLessThanOrEqual(4096, mb_strlen($part)));

        // Нічого не загубили й не розірвали теги: склеєні частини — той самий текст.
        $this->assertSame($text, $sent->implode("\n"));
    }

    public function test_a_short_message_stays_one_message(): void
    {
        app(TelegramService::class)->send('1', 'Звіт за тиждень');

        Http::assertSentCount(1);
    }

    public function test_buttons_go_under_the_last_part(): void
    {
        app(TelegramService::class)->sendMessage('1', $this->longReport(), [[['text' => 'Погодити', 'callback_data' => 'ok']]]);

        $requests = Http::recorded()->map(fn ($pair) => $pair[0]);
        $this->assertArrayHasKey('reply_markup', $requests->last()->data());
        $requests->slice(0, -1)->each(fn (Request $r) => $this->assertArrayNotHasKey('reply_markup', $r->data()));
    }
}
