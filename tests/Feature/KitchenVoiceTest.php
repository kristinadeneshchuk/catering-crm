<?php

namespace Tests\Feature;

use App\Jobs\ReadKitchenVoice;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Services\Ai\OpsAi;
use App\Services\Ai\OveruseReader;
use App\Services\Ai\SpeechToText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsStockTestSchema;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * «Взяли ще два кіло курки на індивідуальні» → чернетка списання поза нормою.
 * Склад рухається лише після проведення адміном.
 */
class KitchenVoiceTest extends TestCase
{
    use CourierShiftScenario;
    use BuildsStockTestSchema;

    protected Ingredient $chicken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        $this->buildStockSchema();

        config()->set('services.telegram.kitchen_chat_id', '-5116331458');
        config()->set('services.anthropic.key', 'test-key');
        config()->set('services.openai.key', 'test-openai');

        DB::table('warehouses')->insert(['name' => 'Кухня']);
        $this->chicken = Ingredient::create(['name' => 'Філе куряче', 'unit' => 'kg', 'stock' => 20, 'price_per_kg' => 215]);
    }

    protected function fake(?string $transcript, ?array $answer): void
    {
        $this->app->bind(SpeechToText::class, fn () => new class($transcript) extends SpeechToText
        {
            public function __construct(private ?string $transcript)
            {
            }

            public function transcribe(string $path, string $language = 'uk'): ?string
            {
                return $this->transcript;
            }
        });

        $this->app->bind(OpsAi::class, fn () => new class($answer) extends OpsAi
        {
            public function __construct(private ?array $answer)
            {
            }

            protected function send(string $system, string $prompt, array $schema, array $imagePaths): array
            {
                return ['text' => json_encode($this->answer), 'usage' => ['input' => 3000, 'output' => 200]];
            }
        });
    }

    protected function answer(array $overrides = []): array
    {
        return array_merge([
            'items' => [[
                'match' => 'ING '.$this->chicken->id, 'quantity' => 2, 'unit' => 'кг',
                'pack_size' => null, 'pack_unit' => null, 'reason' => 'individual',
                'note' => 'взяли ще два кіло курки на індивідуальні',
            ]],
            'unclear'    => null,
            'confidence' => 'high',
        ], $overrides);
    }

    protected function runJob(): void
    {
        Storage::disk('local')->put('ops/voice/v.ogg', 'audio');
        app()->call([new ReadKitchenVoice('ops/voice/v.ogg', '-5116331458', 77), 'handle']);
    }

    public function test_a_voice_note_becomes_a_write_off_draft(): void
    {
        $this->fake('Взяли ще два кіло курки на індивідуальні', $this->answer());

        $this->runJob();

        $document = StockDocument::first();
        $this->assertSame('write_off', $document->type);
        $this->assertTrue($document->isDraft());
        $this->assertSame(StockDocument::SOURCE_AI, $document->source);
        $this->assertEquals(2, (float) $document->items()->first()->qty);
        $this->assertEquals(430, (float) $document->total_sum, 'вартість за середньою ціною закупівлі');
        $this->assertStringContainsString('індивідуальні меню', $document->ai_comment);
        $this->assertStringContainsString('два кіло курки', $document->ai_comment);

        // Склад чекає на проведення.
        $this->assertEquals(20, (float) $this->chicken->fresh()->stock);
        $document->post(1);
        $this->assertEquals(18, (float) $this->chicken->fresh()->stock);
    }

    public function test_the_kitchen_chat_gets_only_a_reaction_and_the_owner_gets_the_details(): void
    {
        $this->fake('Викинули кіло огірків, зіпсувались', $this->answer([
            'items' => [[
                'match' => 'ING '.$this->chicken->id, 'quantity' => 1, 'unit' => 'кг',
                'pack_size' => null, 'pack_unit' => null, 'reason' => 'spoiled', 'note' => 'зіпсувались',
            ]],
        ]));

        $this->runJob();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'setMessageReaction')
            && (string) $r['chat_id'] === '-5116331458');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '100'
            && str_contains($r['text'], 'Списання поза нормою')
            && str_contains($r['text'], 'зіпсувалось'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '-5116331458');
    }

    public function test_an_unrecognised_voice_note_writes_to_the_owner(): void
    {
        $this->fake(null, null);

        $this->runJob();

        $this->assertSame(0, StockDocument::count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'], 'не вдалося розпізнати'));
    }

    public function test_an_unknown_product_is_not_guessed(): void
    {
        $this->fake('Взяли щось незрозуміле', ['items' => [], 'unclear' => 'не зрозумів товар', 'confidence' => 'low']);

        $this->runJob();

        $this->assertSame(0, StockDocument::count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'], 'Не зрозумів, який це товар'));
    }

    protected function voiceMessage(int $messageId = 96): array
    {
        return [
            'update_id' => 95,
            'message' => [
                'message_id' => $messageId,
                'chat'       => ['id' => -5116331458, 'type' => 'group', 'title' => 'Кухня'],
                'from'       => ['id' => 700, 'first_name' => 'Олена'],
                'voice'      => ['file_id' => 'voice-1', 'duration' => 7],
            ],
        ];
    }

    protected function press(int $fromId, string $action, int $messageId = 96): void
    {
        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 97,
            'callback_query' => [
                'id'      => 'cb-voice',
                'from'    => ['id' => $fromId],
                'data'    => "voice:{$action}:-5116331458:{$messageId}",
                'message' => ['message_id' => 5, 'chat' => ['id' => 100], 'text' => 'Голосове в чаті кухні'],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
    }

    public function test_a_voice_note_only_asks_the_owner_and_burns_no_tokens(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/telegram-bot', $this->voiceMessage(), ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        Queue::assertNothingPushed();
        $this->assertSame(0, \App\Models\AiRun::count(), 'без кнопки — жодного звернення до моделі');

        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'sendMessage')) {
                return false;
            }

            $keyboard = $r['reply_markup'] ?? [];
            $keyboard = is_string($keyboard) ? json_decode($keyboard, true) : $keyboard;
            $labels   = collect($keyboard['inline_keyboard'] ?? [])->flatten(1)->pluck('text');

            return (string) $r['chat_id'] === '100'
                && str_contains($r['text'], 'Олена')
                && $labels->contains('🔎 Розібрати');
        });
    }

    public function test_the_button_starts_the_job_once(): void
    {
        Queue::fake();
        $this->postJson('/webhooks/telegram-bot', $this->voiceMessage(), ['X-Telegram-Bot-Api-Secret-Token' => 'sec']);

        $this->press(100, 'run');
        Queue::assertPushed(ReadKitchenVoice::class, 1);

        // Друге натискання — файл уже забраний з кешу, повторного розбору немає.
        $this->press(100, 'run');
        Queue::assertPushed(ReadKitchenVoice::class, 1);
    }

    public function test_skip_and_strangers_do_nothing(): void
    {
        Queue::fake();
        $this->postJson('/webhooks/telegram-bot', $this->voiceMessage(), ['X-Telegram-Bot-Api-Secret-Token' => 'sec']);

        $this->press(999, 'run'); // сторонній
        Queue::assertNothingPushed();

        $this->press(100, 'skip');
        Queue::assertNothingPushed();
        $this->assertNull(\Illuminate\Support\Facades\Cache::get('kitchen-voice:-5116331458:96'), 'після пропуску файл не чекає');
    }
}
