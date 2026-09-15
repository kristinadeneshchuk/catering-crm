<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\Transaction;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsStockTestSchema;
use Tests\TestCase;

/**
 * Чернетка накладної чи списання видна в CRM, але не рухає ні залишків, ні
 * середньої ціни, ні каси — поки адмін її не проведе. Проведення — рівно один раз.
 */
class StockDocumentDraftTest extends TestCase
{
    use BuildsStockTestSchema;

    protected Ingredient $chicken;

    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        $this->buildStockSchema();
        Ingredient::clearAveragePriceCache();

        $this->warehouseId = DB::table('warehouses')->insertGetId(['name' => 'Кухня']);
        $this->chicken     = Ingredient::create(['name' => 'Філе курки', 'unit' => 'kg', 'price_per_kg' => 200, 'stock' => 10]);
        Account::create(['name' => 'ФОП Горенко', 'type' => 'card', 'is_default' => true]);
    }

    protected function receipt(string $status, float $qty = 5, float $total = 1250, bool $paid = false): StockDocument
    {
        $doc = StockDocument::create([
            'type' => 'receipt', 'warehouse_id' => $this->warehouseId, 'operation_date' => now(),
            'status' => $status, 'is_paid' => $paid,
        ]);

        $doc->items()->create([
            'itemable_type' => Ingredient::class, 'itemable_id' => $this->chicken->id,
            'qty' => $qty, 'total_price' => $total,
        ]);

        return $doc->fresh();
    }

    public function test_a_posted_document_still_moves_stock_as_before(): void
    {
        $this->receipt(StockDocument::STATUS_POSTED);

        $this->assertEquals(15, (float) $this->chicken->fresh()->stock);
    }

    public function test_a_draft_moves_nothing(): void
    {
        $doc = $this->receipt(StockDocument::STATUS_DRAFT, paid: true);

        $this->assertTrue($doc->isDraft());
        $this->assertEquals(10, (float) $this->chicken->fresh()->stock, 'склад не змінився');
        $this->assertEquals(1250, (float) $doc->total_sum, 'суму документа видно');
        $this->assertSame(0, Transaction::count(), 'у касу чернетка не йде, навіть «оплачена»');
        $this->assertEquals(200, $this->chicken->fresh()->average_price, 'середня ціна не зсунулась');
        Bus::assertNotDispatched(\App\Jobs\RecalculateDailyMenuCosts::class);
    }

    public function test_editing_and_deleting_a_draft_moves_nothing(): void
    {
        $doc  = $this->receipt(StockDocument::STATUS_DRAFT);
        $item = $doc->items()->first();

        $item->update(['qty' => 8, 'total_price' => 2000]);
        $this->assertEquals(10, (float) $this->chicken->fresh()->stock);

        $doc->delete();
        $this->assertEquals(10, (float) $this->chicken->fresh()->stock, 'видалення чернетки нічого не відкочує');
    }

    public function test_posting_applies_everything_exactly_once(): void
    {
        $doc = $this->receipt(StockDocument::STATUS_DRAFT, paid: true);

        $this->assertTrue($doc->post(7));
        $this->assertFalse($doc->post(7), 'повторне проведення нічого не робить');

        $doc = $doc->fresh();
        $this->assertFalse($doc->isDraft());
        $this->assertSame(7, (int) $doc->posted_by);
        $this->assertEquals(15, (float) $this->chicken->fresh()->stock);
        $this->assertSame(1, Transaction::where('stock_document_id', $doc->id)->count());
        $this->assertEquals(250, Ingredient::find($this->chicken->id)->average_price, '1250 ₴ / 5 кг');
    }

    public function test_drafts_are_left_out_of_average_prices(): void
    {
        $this->receipt(StockDocument::STATUS_POSTED, qty: 5, total: 1000);   // 200 ₴/кг
        $this->receipt(StockDocument::STATUS_DRAFT, qty: 5, total: 5000);    // 1000 ₴/кг — не рахується

        Ingredient::clearAveragePriceCache();
        $this->assertEquals(200, Ingredient::find($this->chicken->id)->average_price);

        Ingredient::clearAveragePriceCache();
        Ingredient::preloadAveragePrices();
        $this->assertEquals(200, Ingredient::find($this->chicken->id)->average_price);
    }

    public function test_a_write_off_draft_does_not_take_from_stock(): void
    {
        $doc = StockDocument::create([
            'type' => 'write_off', 'warehouse_id' => $this->warehouseId, 'operation_date' => now(),
            'status' => StockDocument::STATUS_DRAFT, 'source' => StockDocument::SOURCE_AI,
            'ai_comment' => 'Голосове: «взяли ще два кіло курки на індивідуальні»',
        ]);
        $doc->items()->create(['itemable_type' => Ingredient::class, 'itemable_id' => $this->chicken->id, 'qty' => 2, 'total_price' => 400]);

        $this->assertEquals(10, (float) $this->chicken->fresh()->stock);

        $doc->post(1);
        $this->assertEquals(8, (float) $this->chicken->fresh()->stock);
    }

    // --- екрани ----------------------------------------------------------------

    protected function actingAsRole(string $role): void
    {
        \Illuminate\Support\Facades\Schema::hasTable('users') || \Illuminate\Support\Facades\Schema::create('users', function ($t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role')->default('manager'); $t->rememberToken(); $t->timestamps();
        });
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs(\App\Models\User::create(['name' => $role, 'email' => $role.'@t.test', 'password' => 'x', 'role' => $role]));
    }

    public function test_the_admin_posts_a_draft_from_the_list(): void
    {
        $this->actingAsRole('admin');
        $doc = $this->receipt(StockDocument::STATUS_DRAFT);

        \Livewire\Livewire::test(\App\Filament\Resources\StockDocumentResource\Pages\ListStockDocuments::class)
            ->assertCanSeeTableRecords([$doc])
            ->assertTableActionHidden('toggle_paid', $doc)
            ->callTableAction('post', $doc)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($doc->fresh()->isDraft());
        $this->assertEquals(15, (float) $this->chicken->fresh()->stock);
    }

    public function test_only_the_admin_can_post(): void
    {
        $this->actingAsRole('manager');
        $doc = $this->receipt(StockDocument::STATUS_DRAFT);

        \Livewire\Livewire::test(\App\Filament\Resources\StockDocumentResource\Pages\ListStockDocuments::class)
            ->assertTableActionHidden('post', $doc);
    }

    public function test_the_draft_card_shows_the_ai_comment_and_photo(): void
    {
        $this->actingAsRole('admin');
        $doc = $this->receipt(StockDocument::STATUS_DRAFT);
        $doc->update(['source' => StockDocument::SOURCE_AI, 'ai_comment' => 'Овочі-Опт, накладна №118', 'attachments' => ['ops/invoices/118.jpg']]);

        \Livewire\Livewire::test(\App\Filament\Resources\StockDocumentResource\Pages\EditStockDocument::class, ['record' => $doc->id])
            ->assertSee('Овочі-Опт, накладна №118')
            ->assertSee('118.jpg')
            ->assertActionVisible('post');
    }
}
