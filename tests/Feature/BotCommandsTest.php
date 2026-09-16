<?php

namespace Tests\Feature;

use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Services\Ai\BotQueries;
use App\Services\Ai\PriceWatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsStockTestSchema;
use Tests\Support\CourierShiftScenario;
use Tests\TestCase;

/**
 * Команди боту: чернетки, залишок, ціни, витрати. Усі лише читають.
 * Плюс порівняння цін нової накладної з минулими закупівлями.
 */
class BotCommandsTest extends TestCase
{
    use CourierShiftScenario;
    use BuildsStockTestSchema;

    protected Ingredient $chicken;

    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCourierWorld();
        $this->buildStockSchema();

        $this->warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Кухня']);
        $this->chicken     = Ingredient::create(['name' => 'Філе куряче', 'unit' => 'kg', 'stock' => 12.5, 'price_per_kg' => 200]);
    }

    protected function receipt(float $qty, float $total, string $date, string $status = StockDocument::STATUS_POSTED, ?int $supplierId = null): StockDocument
    {
        $document = StockDocument::create([
            'type' => 'receipt', 'warehouse_id' => $this->warehouseId, 'operation_date' => $date,
            'status' => $status, 'supplier_id' => $supplierId,
        ]);

        $document->items()->create([
            'itemable_type' => Ingredient::class, 'itemable_id' => $this->chicken->id,
            'qty' => $qty, 'total_price' => $total,
        ]);

        return $document->fresh();
    }

    protected function ask(string $text): ?string
    {
        return app(BotQueries::class)->handle($text);
    }

    public function test_plain_text_is_not_a_command(): void
    {
        $this->assertNull($this->ask('привіт'));
        $this->assertNull($this->ask('/невідома'));
    }

    public function test_drafts_lists_what_waits_for_a_human(): void
    {
        $this->assertStringContainsString('немає', $this->ask('/чернетки'));

        $supplier = Supplier::create(['name' => 'Столичка']);
        $draft    = $this->receipt(10, 2000, '2026-09-16', StockDocument::STATUS_DRAFT, $supplier->id);
        $draft->update(['ai_state' => ['pending' => [['name' => 'Лаваш']]]]);

        $answer = $this->ask('/чернетки');

        $this->assertStringContainsString('Столичка', $answer);
        $this->assertStringContainsString('1 поз.', $answer);
        $this->assertStringContainsString('чекає фасування: 1', $answer);
        $this->assertStringContainsString('stock-documents/'.$draft->id, $answer);
    }

    public function test_stock_shows_the_balance_and_the_average_price(): void
    {
        $this->receipt(10, 2000, '2026-09-10'); // 200 ₴/кг

        $answer = $this->ask('/склад куряч');

        $this->assertStringContainsString('Філе куряче', $answer);
        $this->assertStringContainsString('22,5 кг', $answer, 'залишок оновився після проведення');
        $this->assertStringContainsString('200,00 ₴/кг', $answer);
        $this->assertStringContainsString('Напишіть, що шукати', $this->ask('/склад'));
    }

    public function test_prices_show_history_and_the_trend(): void
    {
        $supplier = Supplier::create(['name' => 'Столичка']);
        $this->receipt(10, 1800, '2026-08-20', supplierId: $supplier->id);  // 180
        $this->receipt(10, 2154, '2026-09-16', supplierId: $supplier->id);  // 215,40

        $answer = $this->ask('/ціни куряч');

        $this->assertStringContainsString('215,40 ₴/кг', $answer);
        $this->assertStringContainsString('180,00 ₴/кг', $answer);
        $this->assertStringContainsString('Столичка', $answer);
        $this->assertStringContainsString('зросла на +20%', $answer);
    }

    public function test_costs_show_todays_ai_spending(): void
    {
        AiRun::create(['purpose' => 'invoice', 'model' => 'claude-opus-5', 'status' => 'ok', 'cost_usd' => 0.04]);
        AiRun::create(['purpose' => 'invoice', 'model' => 'claude-opus-5', 'status' => 'failed', 'cost_usd' => 0]);

        $answer = $this->ask('/витрати');

        $this->assertStringContainsString('$0.04', $answer);
        $this->assertStringContainsString('запусків 2', $answer);
        $this->assertStringContainsString('невдалих 1', $answer);
    }

    public function test_help_lists_the_commands(): void
    {
        $this->assertStringContainsString('/склад', $this->ask('/допомога'));
        $this->assertStringContainsString('/склад', $this->ask('/help'));
        $this->assertNull($this->ask('/start ABC123'), 'підключення курʼєра — не довідка');
    }

    public function test_commands_work_through_the_bot_for_staff_only(): void
    {
        $send = fn (int $chatId) => $this->postJson('/webhooks/telegram-bot', [
            'update_id' => 80,
            'message' => [
                'message_id' => 81,
                'chat'       => ['id' => $chatId, 'type' => 'private'],
                'from'       => ['id' => $chatId],
                'text'       => '/витрати',
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'sec'])->assertOk();

        $send(100); // власник
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
            && str_contains($r['text'], 'Витрати на ШІ'));

        Http::fake(); // чистимо історію
        $send(999);   // сторонній
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Витрати на ШІ'));
    }

    // --- порівняння цін у новій накладній -------------------------------------

    public function test_a_price_jump_is_reported_with_the_impact(): void
    {
        $this->receipt(10, 1800, '2026-08-20');                                   // було 180 ₴/кг
        $draft = $this->receipt(20, 4308, '2026-09-16', StockDocument::STATUS_DRAFT); // стало 215,40

        $summary = app(PriceWatcher::class)->summary($draft);

        $this->assertStringContainsString('Філе куряче', $summary);
        $this->assertStringContainsString('215,40 ₴/кг', $summary);
        $this->assertStringContainsString('було 180,00 ₴', $summary);
        $this->assertStringContainsString('+20%', $summary);
        $this->assertStringContainsString('+708,00 ₴ на цю накладну', $summary);
    }

    public function test_small_changes_are_not_noise(): void
    {
        $this->receipt(10, 2000, '2026-08-20');                                    // 200
        $draft = $this->receipt(10, 2050, '2026-09-16', StockDocument::STATUS_DRAFT); // 205, +2,5%

        $this->assertNull(app(PriceWatcher::class)->summary($draft));
    }

    public function test_drafts_are_not_a_price_reference(): void
    {
        $this->receipt(10, 5000, '2026-09-15', StockDocument::STATUS_DRAFT);       // чернетка, 500 ₴/кг
        $this->receipt(10, 2000, '2026-08-20');                                    // факт, 200 ₴/кг
        $draft = $this->receipt(10, 2400, '2026-09-16', StockDocument::STATUS_DRAFT);

        $summary = app(PriceWatcher::class)->summary($draft);

        $this->assertStringContainsString('було 200,00 ₴', $summary, 'порівнюємо лише з проведеними');
    }
}
