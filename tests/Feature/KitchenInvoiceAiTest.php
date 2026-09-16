<?php

namespace Tests\Feature;

use App\Jobs\ReadKitchenInvoice;
use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Services\Ai\InvoiceReader;
use App\Services\Ai\OpsAi;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsStockTestSchema;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Фото накладної з чату кухні → чернетка в CRM (docs/tz-ops-agent.md §3).
 *
 * Модель тут підмінена: перевіряємо не текст ШІ, а те, що з його відповіді
 * виходить правильна чернетка — і що вона нічого не рухає до проведення.
 */
class KitchenInvoiceAiTest extends TestCase
{
    use CourierShiftScenario;
    use BuildsStockTestSchema;

    protected Ingredient $chicken;

    protected Packaging $box;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        $this->buildStockSchema();

        config()->set('services.telegram.kitchen_chat_id', '-5116331458');
        config()->set('services.anthropic.key', 'test-key');

        DB::table('warehouses')->insert(['name' => 'Кухня']);
        $this->chicken = Ingredient::create(['name' => 'Філе куряче', 'unit' => 'kg', 'price_per_kg' => 200, 'stock' => 4]);
        $this->box     = Packaging::create(['name' => 'Бокс 500', 'unit' => 'шт', 'stock' => 100]);
        Supplier::create(['name' => 'ТОВ «Овочі-Опт»', 'inn' => '12345678']);
    }

    /** Відповідь моделі підміняємо — мережі в тестах немає. */
    protected function fakeAi(?array $answer): void
    {
        $this->app->bind(OpsAi::class, fn () => new class($answer) extends OpsAi
        {
            public function __construct(private ?array $answer)
            {
            }

            protected function send(string $system, string $prompt, array $schema, array $imagePaths): array
            {
                return [
                    'text'  => $this->answer === null ? 'вибачте, не бачу накладної' : json_encode($this->answer),
                    'usage' => ['input' => 4000, 'output' => 600, 'cache_read' => 0],
                ];
            }
        });
    }

    protected function answer(array $overrides = []): array
    {
        return array_merge([
            'supplier_name' => 'Овочі-Опт',
            'supplier_code' => '12345678',
            'number'        => '118',
            'date'          => '2026-09-16',
            'total'         => 3200,
            'items'         => [
                ['raw_name' => 'Філе куряче охол.', 'match' => 'ING '.$this->chicken->id, 'quantity' => 10, 'unit' => 'кг', 'total_price' => 2200, 'note' => null],
                ['raw_name' => 'Бокс 500 мл', 'match' => 'PACK '.$this->box->id, 'quantity' => 200, 'unit' => 'шт', 'total_price' => 800, 'note' => null],
                ['raw_name' => 'Соус невідомий', 'match' => null, 'quantity' => 5, 'unit' => 'шт', 'total_price' => 200, 'note' => 'нерозбірливо'],
            ],
            'issues'     => ['Сума рядків 3 200 ₴ збігається з підсумком'],
            'confidence' => 'high',
        ], $overrides);
    }

    protected function photo(): string
    {
        Storage::disk('local')->put('ops/invoices/2026-09/inv.jpg', 'jpeg-bytes');

        return 'ops/invoices/2026-09/inv.jpg';
    }

    public function test_a_photo_becomes_a_draft_that_moves_nothing(): void
    {
        $this->fakeAi($this->answer());

        $document = app(InvoiceReader::class)->fromPhotos([$this->photo()]);

        $this->assertTrue($document->isDraft());
        $this->assertSame(StockDocument::SOURCE_AI, $document->source);
        $this->assertSame('ТОВ «Овочі-Опт»', $document->supplier?->name, 'постачальника звʼязано з наявним за кодом');
        $this->assertSame(2, $document->items()->count(), 'у документ ідуть лише зіставлені рядки');
        $this->assertEquals(3000, (float) $document->total_sum);

        // Склад і ціни не рухались.
        $this->assertEquals(4, (float) $this->chicken->fresh()->stock);
        $this->assertEquals(100, (float) $this->box->fresh()->stock);

        // Нерозпізнаний рядок не загубився — він у коментарі для людини.
        $this->assertStringContainsString('Соус невідомий', $document->ai_comment);
        $this->assertStringContainsString('Не зіставлено', $document->ai_comment);

        // Провели — тільки тепер склад змінився.
        $document->post(1);
        $this->assertEquals(14, (float) $this->chicken->fresh()->stock);
    }

    public function test_the_call_is_logged_with_tokens_and_cost(): void
    {
        $this->fakeAi($this->answer());

        app(InvoiceReader::class)->fromPhotos([$this->photo()], ['chat' => 'kitchen']);

        $run = AiRun::first();
        $this->assertSame(AiRun::PURPOSE_INVOICE, $run->purpose);
        $this->assertSame('ok', $run->status);
        $this->assertSame(4000, $run->input_tokens);
        $this->assertEqualsWithDelta(0.035, (float) $run->cost_usd, 0.0001, '4000 вхідних + 600 вихідних на Opus 5');
    }

    public function test_a_bad_answer_creates_nothing_and_is_logged(): void
    {
        $this->fakeAi(null);

        $this->assertNull(app(InvoiceReader::class)->fromPhotos([$this->photo()]));
        $this->assertSame(0, StockDocument::count());
        $this->assertSame('invalid', AiRun::first()->status);
    }

    public function test_the_daily_cap_stops_the_ai(): void
    {
        config()->set('services.anthropic.daily_cap_usd', 1);
        AiRun::create(['purpose' => 'invoice', 'model' => 'claude-opus-5', 'status' => 'ok', 'cost_usd' => 1.2]);
        $this->fakeAi($this->answer());

        $this->assertNull(app(InvoiceReader::class)->fromPhotos([$this->photo()]));
        $this->assertSame(0, StockDocument::count());
        $this->assertStringContainsString('ліміт', AiRun::latest('id')->first()->error);
    }

    public function test_an_album_from_the_kitchen_chat_starts_one_job(): void
    {
        Queue::fake();

        foreach ([11, 12] as $i => $messageId) {
            $this->postJson('/webhooks/telegram-bot', [
                'update_id' => 10 + $i,
                'message' => [
                    'message_id'     => $messageId,
                    'media_group_id' => 'album-1',
                    'chat'           => ['id' => -5116331458, 'type' => 'group', 'title' => 'Кухня'],
                    'from'           => ['id' => 700],
                    'photo'          => [['file_id' => 'small'.$messageId], ['file_id' => 'big'.$messageId]],
                ],
            ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();
        }

        Queue::assertPushed(ReadKitchenInvoice::class, 1);
        $this->assertCount(2, \Illuminate\Support\Facades\Cache::get('kitchen-invoice:album-1', []), 'обидві сторінки в одній накладній');
    }

    public function test_the_owner_can_send_an_invoice_straight_to_the_bot(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 40,
            'message' => [
                'message_id' => 41,
                'chat'       => ['id' => 100, 'type' => 'private'],
                'from'       => ['id' => 100],
                'photo'      => [['file_id' => 'small'], ['file_id' => 'big']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        Queue::assertPushed(ReadKitchenInvoice::class, 1);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '100'
            && str_contains($r['text'], 'Прийняв накладну'));
    }

    public function test_the_answer_comes_back_to_the_same_private_chat(): void
    {
        $this->fakeAi($this->answer());
        \Illuminate\Support\Facades\Cache::put('kitchen-invoice:single:41', [$this->photo()], now()->addMinutes(5));

        app()->call([new ReadKitchenInvoice('kitchen-invoice:single:41', '100', 41, '100'), 'handle']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && (string) $r['chat_id'] === '100'
            && str_contains($r['text'], 'Чернетка накладної')
            && str_contains($r['text'], 'Соус невідомий'));
    }

    public function test_a_courier_photo_in_private_is_still_an_odometer(): void
    {
        Queue::fake();
        $this->courier->update(['telegram_chat_id' => '100']); // курʼєр і власник в одному чаті — крайній випадок

        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 42,
            'message' => [
                'message_id' => 43,
                'chat'       => ['id' => 100, 'type' => 'private'],
                'from'       => ['id' => 100],
                'photo'      => [['file_id' => 'odo']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_photos_from_other_chats_are_ignored(): void
    {
        Queue::fake();

        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 20,
            'message' => [
                'message_id' => 30,
                'chat'       => ['id' => -1003969659267, 'type' => 'supergroup', 'title' => 'ЗП Курʼєри'],
                'from'       => ['id' => 700],
                'photo'      => [['file_id' => 'x']],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_the_job_reacts_in_the_chat_and_writes_to_the_owner(): void
    {
        $this->fakeAi($this->answer());
        \Illuminate\Support\Facades\Cache::put('kitchen-invoice:album-2', [$this->photo()], now()->addMinutes(5));

        app()->call([new ReadKitchenInvoice('kitchen-invoice:album-2', '-5116331458', 11), 'handle']);

        Http::assertSent(fn ($r) => str_contains($r->url(), 'setMessageReaction')
            && $r['chat_id'] === '-5116331458');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && $r['chat_id'] === '100'
            && str_contains($r['text'], 'Чернетка накладної'));
    }
}
