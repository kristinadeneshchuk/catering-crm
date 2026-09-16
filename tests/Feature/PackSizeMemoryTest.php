<?php

namespace Tests\Feature;

use App\Jobs\ReadKitchenInvoice;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Services\Ai\InvoiceReader;
use App\Services\Ai\OpsAi;
use App\Services\Ai\PackSizeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsStockTestSchema;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * «Скільки важить одна штука» бот питає один раз: далі фасування живе в
 * картці товару, і наступні накладні рахуються самі.
 */
class PackSizeMemoryTest extends TestCase
{
    use CourierShiftScenario;
    use BuildsStockTestSchema;

    protected Ingredient $lavash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        $this->buildStockSchema();

        config()->set('services.anthropic.key', 'test-key');
        DB::table('warehouses')->insert(['name' => 'Кухня']);
        $this->lavash = Ingredient::create(['name' => 'Лаваш', 'unit' => 'kg', 'stock' => 0]);
    }

    protected function fakeAi(array $answer): void
    {
        $this->app->bind(OpsAi::class, fn () => new class($answer) extends OpsAi
        {
            public function __construct(private array $answer)
            {
            }

            protected function send(string $system, string $prompt, array $schema, array $imagePaths): array
            {
                // Другий виклик (розбір відповіді людини) іде без фото.
                $key = $imagePaths === [] ? 'reply' : 'invoice';

                return ['text' => json_encode($this->answer[$key]), 'usage' => ['input' => 100, 'output' => 50]];
            }
        });
    }

    protected function invoiceAnswer(): array
    {
        return [
            'supplier_name' => 'Столичка', 'supplier_code' => null, 'number' => '5', 'date' => '2026-09-16',
            'total' => 875, 'issues' => [], 'confidence' => 'high',
            'items' => [[
                'raw_name' => 'Лаваш Вірменський', 'match' => 'ING '.$this->lavash->id, 'quantity' => 7,
                'unit' => 'шт', 'pack_size' => null, 'pack_unit' => null, 'total_price' => 875, 'note' => null,
            ]],
        ];
    }

    protected function draft(): StockDocument
    {
        Storage::disk('local')->put('ops/invoices/inv.jpg', 'bytes');

        return app(InvoiceReader::class)->fromPhotos(['ops/invoices/inv.jpg']);
    }

    public function test_an_unknown_pack_size_becomes_a_question_and_then_memory(): void
    {
        $this->fakeAi([
            'invoice' => $this->invoiceAnswer(),
            'reply'   => ['items' => [['item_id' => $this->lavash->id, 'pack_size' => 0.5, 'pack_unit' => 'кг']]],
        ]);

        $document = $this->draft();
        $this->assertCount(1, $document->ai_state['pending']);
        $this->assertStringContainsString('Лаваш', app(PackSizeResolver::class)->question($document));
        $this->assertEquals(7, (float) $document->items()->first()->qty, 'поки що як у накладній');

        $result = app(PackSizeResolver::class)->apply($document, 'лаваш 0,5 кг');

        $this->assertSame(1, $result['applied']);
        $this->assertStringContainsString('Лаваш — 0,5 кг', $result['text']);

        // Рядок перерахований: 7 шт × 0,5 кг = 3,5 кг.
        $this->assertEquals(3.5, (float) $document->fresh()->items()->first()->qty);
        $this->assertSame([], $document->fresh()->ai_state['pending']);

        // Фасування запамʼятали в картці товару.
        $this->assertEquals(0.5, (float) $this->lavash->fresh()->package_weight);
        $this->assertSame('кг', $this->lavash->fresh()->package_unit);

        // Проведення кладе на склад правильну вагу.
        $document->fresh()->post(1);
        $this->assertEquals(3.5, (float) $this->lavash->fresh()->stock);
    }

    public function test_the_next_invoice_needs_no_question(): void
    {
        $this->lavash->update(['package_weight' => 0.5, 'package_unit' => 'кг', 'is_packaged' => true]);
        $this->fakeAi(['invoice' => $this->invoiceAnswer(), 'reply' => ['items' => []]]);

        $document = $this->draft();

        $this->assertNull($document->ai_state['pending'] ?? null);
        $this->assertEquals(3.5, (float) $document->items()->first()->qty);
    }

    public function test_the_reply_comes_back_through_the_bot(): void
    {
        $this->fakeAi([
            'invoice' => $this->invoiceAnswer(),
            'reply'   => ['items' => [['item_id' => $this->lavash->id, 'pack_size' => 0.5, 'pack_unit' => 'кг']]],
        ]);

        \Illuminate\Support\Facades\Cache::put('kitchen-invoice:pack', ['ops/invoices/inv.jpg'], now()->addMinutes(5));
        Storage::disk('local')->put('ops/invoices/inv.jpg', 'bytes');
        app()->call([new ReadKitchenInvoice('kitchen-invoice:pack', '100', 11, '100'), 'handle']);

        $document = StockDocument::first();
        $askId    = $document->ai_state['ask']['message_id'] ?? null;
        $this->assertNotNull($askId, 'питання надіслано, id збережено');

        $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 70,
            'message' => [
                'message_id'       => 99,
                'chat'             => ['id' => 100, 'type' => 'private'],
                'from'             => ['id' => 100],
                'text'             => 'лаваш 0,5 кг',
                'reply_to_message' => ['message_id' => $askId],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $this->assertEquals(3.5, (float) $document->fresh()->items()->first()->qty);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'] ?? '', 'Записав фасування'));
    }
}
