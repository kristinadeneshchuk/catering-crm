<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Services\Kitchen\KitchenStockDebit;
use App\Services\Kitchen\ProductionPlanBuilder;
use App\Services\Kitchen\StockPriceBook;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Списання зі складу за нормою: мапа днів готування → дні їжі, облік уже
 * списаного (легасі-прапорці кнопки), перерахунок у одиниці складу, ціна на
 * дату і захист від подвійного проведення.
 *
 * План виробництва підмінено: норму з техкарт рахує ProductionPlanBuilder,
 * тут перевіряємо лише те, що з нею робить списання.
 */
class KitchenStockDebitTest extends TestCase
{
    private FakePlanBuilder $builder;
    private KitchenStockDebit $debit;

    private Ingredient $chicken;   // кг
    private Ingredient $sriracha;  // г
    private Ingredient $milk;      // «шт» з упаковкою 0.9 — склад насправді в кг
    private Ingredient $bun;       // «шт», вага упаковки «300» — одиниця невідома
    private Ingredient $eggs;      // шт без ваги
    private Packaging $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSchema();

        DB::table('warehouses')->insert([['id' => 3, 'name' => 'Продукти'], ['id' => 4, 'name' => 'Упаковка']]);

        $this->chicken  = Ingredient::create(['name' => 'Курка', 'unit' => 'кг', 'price_per_kg' => 150, 'stock' => 20]);
        $this->sriracha = Ingredient::create(['name' => 'Шрірача', 'unit' => 'г', 'price_per_kg' => 600, 'stock' => 1000]);
        $this->milk     = Ingredient::create(['name' => 'Молоко', 'unit' => 'шт', 'price_per_kg' => 60, 'stock' => 10, 'is_packaged' => true, 'package_weight' => 0.9]);
        $this->bun      = Ingredient::create(['name' => 'Булочка', 'unit' => 'шт', 'price_per_kg' => 50, 'stock' => 100, 'is_packaged' => true, 'package_weight' => 300]);
        $this->eggs     = Ingredient::create(['name' => 'Яйця', 'unit' => 'шт', 'price_per_kg' => 0, 'stock' => 30]);
        $this->box      = Packaging::create(['name' => 'Бокс 500', 'unit' => 'шт', 'price' => 4, 'stock' => 500]);

        $this->builder = new FakePlanBuilder();
        $this->app->instance(ProductionPlanBuilder::class, $this->builder);
        $this->debit = new KitchenStockDebit($this->builder, new StockPriceBook());
    }

    public function test_friday_cooks_for_weekend_and_saturday_is_off(): void
    {
        $dates = fn (string $d) => KitchenStockDebit::foodDatesFor(Carbon::parse($d));

        $this->assertSame(['2026-09-15'], $dates('2026-09-14'));             // пн → вт
        $this->assertSame(['2026-09-19', '2026-09-20'], $dates('2026-09-18')); // пт → сб+нд
        $this->assertSame([], $dates('2026-09-19'));                         // сб — не готує
        $this->assertSame(['2026-09-21'], $dates('2026-09-20'));             // нд → пн
    }

    public function test_legacy_cook_flag_covers_only_next_day(): void
    {
        // Легасі-кнопка у п'ятницю 19.06 списала лише суботу; неділя лишилась боргом.
        $this->flag('stock_debited_2026-06-19');

        $this->assertSame(['2026-06-21'], $this->debit->pendingFoodDates(Carbon::parse('2026-06-19')));
        $this->assertSame([], $this->debit->pendingFoodDates(Carbon::parse('2026-06-20')));
    }

    public function test_post_creates_documents_converts_units_and_decrements_stock(): void
    {
        $this->builder->day('2026-09-15', orders: 12, grams: [
            $this->chicken->id  => 2500,  // 2.5 кг
            $this->sriracha->id => 40,    // 40 г
            $this->milk->id     => 1800,  // 1.8 кг
            $this->bun->id      => 600,   // пропускається
        ], packs: [$this->box->id => 24]);
        $this->receipt('2026-09-01', $this->box, qty: 1000, price: 4, warehouse: 4);

        $plan = $this->debit->plan(Carbon::parse('2026-09-14'));
        $docs = $this->debit->post($plan);

        $this->assertCount(2, $docs);
        $this->assertEqualsWithDelta(17.5, (float) $this->chicken->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(960, (float) $this->sriracha->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(8.2, (float) $this->milk->fresh()->stock, 0.001);
        $this->assertEqualsWithDelta(100, (float) $this->bun->fresh()->stock, 0.001);
        $this->assertSame('Булочка', $plan['skipped'][0]['name']);
        $this->assertEqualsWithDelta(1476, (float) $this->box->fresh()->stock, 0.001); // 500 + прихід 1000 − 24

        [$food, $pack] = $docs;
        $this->assertSame('write_off', $food->type);
        $this->assertSame(3, (int) $food->warehouse_id);
        $this->assertSame(4, (int) $pack->warehouse_id);
        $this->assertStringContainsString('їжа на 15.09', $food->comment);
        // 2.5×150 + 40×0.6 + 1.8×60 = 375 + 24 + 108
        $this->assertEqualsWithDelta(507, (float) $food->total_sum, 0.01);
        $this->assertEqualsWithDelta(96, (float) $pack->total_sum, 0.01);

        $this->assertTrue($this->hasFlag('stock_debited_food_2026-09-15'));
        $this->assertTrue($this->hasFlag('stock_debited_2026-09-14'));
        $this->assertSame([], $this->debit->pendingFoodDates(Carbon::parse('2026-09-14')));
    }

    public function test_price_is_average_of_receipts_up_to_the_date(): void
    {
        $this->receipt('2026-08-01', $this->chicken, qty: 10, price: 100);
        $this->receipt('2026-09-01', $this->chicken, qty: 10, price: 200);
        $this->receipt('2026-09-20', $this->chicken, qty: 100, price: 999); // пізніше — не враховується

        $this->builder->day('2026-08-04', orders: 1, grams: [$this->chicken->id => 1000]);
        $this->builder->day('2026-09-15', orders: 1, grams: [$this->chicken->id => 1000]);

        $august = $this->debit->plan(Carbon::parse('2026-08-03'));
        $september = $this->debit->plan(Carbon::parse('2026-09-14'));

        $this->assertEqualsWithDelta(100, $august['lines'][0]['price'], 0.001);
        $this->assertSame('receipts', $august['lines'][0]['price_source']);
        $this->assertEqualsWithDelta(150, $september['lines'][0]['price'], 0.001);
    }

    public function test_typo_receipt_does_not_skew_price(): void
    {
        // «0,63 г» замість 0,63 л — у накладній 0.001 кг по 130 000 ₴
        $this->receipt('2026-08-10', $this->chicken, qty: 10, price: 150);
        $this->receipt('2026-08-11', $this->chicken, qty: 0.001, price: 130000);
        $this->builder->day('2026-09-15', orders: 1, grams: [$this->chicken->id => 1000]);

        $line = $this->debit->plan(Carbon::parse('2026-09-14'))['lines'][0];

        $this->assertEqualsWithDelta(150, $line['price'], 0.001);
    }

    public function test_draft_receipt_is_ignored_in_price(): void
    {
        $this->receipt('2026-08-10', $this->chicken, qty: 10, price: 150);
        $this->receipt('2026-08-11', $this->chicken, qty: 10, price: 160, status: 'draft'); // накладна з фото на перевірці
        $this->builder->day('2026-09-15', orders: 1, grams: [$this->chicken->id => 1000]);

        $line = $this->debit->plan(Carbon::parse('2026-09-14'))['lines'][0];

        $this->assertEqualsWithDelta(150, $line['price'], 0.001);
    }

    public function test_receipt_price_far_from_card_falls_back_to_card(): void
    {
        $this->receipt('2026-08-10', $this->chicken, qty: 5, price: 1500); // ×10 від картки
        $this->builder->day('2026-09-15', orders: 1, grams: [$this->chicken->id => 1000]);

        $line = $this->debit->plan(Carbon::parse('2026-09-14'))['lines'][0];

        $this->assertEqualsWithDelta(150, $line['price'], 0.001);
        $this->assertSame('card_check', $line['price_source']);
    }

    public function test_pieces_without_package_weight_are_skipped_not_guessed(): void
    {
        $this->builder->day('2026-09-15', orders: 3, grams: [$this->eggs->id => 180, $this->chicken->id => 1000]);

        $plan = $this->debit->plan(Carbon::parse('2026-09-14'));

        $this->assertCount(1, $plan['lines']);
        $this->assertSame('Яйця', $plan['skipped'][0]['name']);

        $this->debit->post($plan);
        $this->assertEqualsWithDelta(30, (float) $this->eggs->fresh()->stock, 0.001);
    }

    public function test_friday_debits_saturday_and_sunday_in_one_document(): void
    {
        $this->builder->day('2026-09-19', orders: 10, grams: [$this->chicken->id => 3000]);
        $this->builder->day('2026-09-20', orders: 8, grams: [$this->chicken->id => 2000]);

        $plan = $this->debit->plan(Carbon::parse('2026-09-18'));
        $docs = $this->debit->post($plan);

        $this->assertSame(['2026-09-19', '2026-09-20'], $plan['food_dates']);
        $this->assertCount(1, $docs);
        $this->assertStringContainsString('їжа на 19.09, 20.09', $docs[0]->comment);
        $this->assertEqualsWithDelta(15, (float) $this->chicken->fresh()->stock, 0.001);
        $this->assertSame([], $this->debit->pendingFoodDates(Carbon::parse('2026-09-19')));
    }

    public function test_same_day_cannot_be_posted_twice(): void
    {
        $this->builder->day('2026-09-15', orders: 1, grams: [$this->chicken->id => 1000]);
        $plan = $this->debit->plan(Carbon::parse('2026-09-14'));

        $this->debit->post($plan);

        try {
            $this->debit->post($plan); // застарілий план — друга вкладка, повторний клік
            $this->fail('Другий проведений раз мав впасти на унікальному прапорці');
        } catch (QueryException) {
        }

        $this->assertEqualsWithDelta(19, (float) $this->chicken->fresh()->stock, 0.001);
        $this->assertSame(1, StockDocument::count());
    }

    public function test_missing_menu_blocks_posting(): void
    {
        $this->builder->day('2026-09-15', orders: 5, grams: [$this->chicken->id => 1000], missing: true);

        $plan = $this->debit->plan(Carbon::parse('2026-09-14'));
        $this->assertTrue($plan['blocking']);

        $this->expectException(RuntimeException::class);
        try {
            $this->debit->post($plan);
        } finally {
            $this->assertSame(0, StockDocument::count());
            $this->assertFalse($this->hasFlag('stock_debited_food_2026-09-15'));
        }
    }

    public function test_day_without_orders_is_closed_without_document(): void
    {
        $plan = $this->debit->plan(Carbon::parse('2026-09-14'));
        $this->assertSame([], $this->debit->post($plan));
        $this->assertTrue($this->hasFlag('stock_debited_food_2026-09-15'));
    }

    public function test_command_dry_run_writes_nothing_and_apply_closes_range(): void
    {
        $this->flag('stock_debited_2026-09-14'); // понеділок закрила кнопка
        $this->builder->day('2026-09-16', orders: 4, grams: [$this->chicken->id => 1000]);
        $this->builder->day('2026-09-17', orders: 4, grams: [$this->chicken->id => 2000]);

        $this->artisan('stock:debit-norm', ['--from' => '2026-09-14', '--to' => '2026-09-16', '--dry-run' => true])
            ->assertSuccessful();
        $this->assertSame(0, StockDocument::count());
        $this->assertEqualsWithDelta(20, (float) $this->chicken->fresh()->stock, 0.001);

        $this->artisan('stock:debit-norm', ['--from' => '2026-09-14', '--to' => '2026-09-16'])
            ->assertSuccessful();
        $this->assertSame(2, StockDocument::count());
        $this->assertEqualsWithDelta(17, (float) $this->chicken->fresh()->stock, 0.001);

        // Повторний запуск нічого не додає
        $this->artisan('stock:debit-norm', ['--from' => '2026-09-14', '--to' => '2026-09-16'])->assertSuccessful();
        $this->assertSame(2, StockDocument::count());
    }

    public function test_command_fails_when_menu_is_missing(): void
    {
        $this->builder->day('2026-09-15', orders: 5, grams: [$this->chicken->id => 1000], missing: true);

        $this->artisan('stock:debit-norm', ['--date' => '2026-09-14'])->assertFailed();
        $this->assertSame(0, StockDocument::count());
    }

    private function flag(string $key): void
    {
        DB::table('settings')->insert(['key' => $key, 'value' => '1']);
    }

    private function hasFlag(string $key): bool
    {
        return DB::table('settings')->where('key', $key)->where('value', '1')->exists();
    }

    private function receipt(string $date, Ingredient|Packaging $item, float $qty, float $price, int $warehouse = 3, string $status = 'completed'): void
    {
        $doc = StockDocument::create(['type' => 'receipt', 'warehouse_id' => $warehouse, 'operation_date' => $date, 'status' => $status, 'total_sum' => 0]);
        StockDocumentItem::create([
            'stock_document_id' => $doc->id, 'itemable_type' => $item::class, 'itemable_id' => $item->id,
            'qty' => $qty, 'price' => $price, 'total_price' => $qty * $price,
        ]);
    }

    private function buildSchema(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->string('value')->nullable(); $t->timestamps();
        });
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->timestamps();
        });
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('unit')->default('г');
            $t->decimal('price_per_kg', 8, 2)->nullable(); $t->integer('yield_percent')->default(100);
            $t->decimal('stock', 10, 3)->default(0);
            $t->boolean('is_packaged')->default(false); $t->decimal('package_weight', 10, 3)->nullable(); $t->string('package_unit')->nullable();
            $t->timestamps();
        });
        Schema::create('packagings', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('unit')->nullable(); $t->decimal('stock', 10, 3)->default(0);
            $t->decimal('price', 10, 2)->default(0); $t->string('packaging_type')->nullable(); $t->timestamps();
        });
        Schema::create('stock_documents', function (Blueprint $t) {
            $t->id(); $t->string('type'); $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('supplier_id')->nullable(); $t->unsignedBigInteger('account_id')->nullable();
            $t->dateTime('operation_date'); $t->string('status')->default('completed'); $t->text('comment')->nullable();
            $t->decimal('total_sum', 10, 2)->default(0); $t->boolean('is_paid')->default(false); $t->timestamps();
        });
        Schema::create('stock_document_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('stock_document_id'); $t->morphs('itemable');
            $t->decimal('qty', 10, 3); $t->decimal('price', 12, 4)->default(0); $t->decimal('total_price', 10, 2)->default(0);
            $t->decimal('input_qty', 10, 3)->nullable(); $t->string('input_unit')->nullable();
            $t->timestamps();
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('stock_document_id')->nullable(); $t->decimal('amount', 10, 2)->default(0);
            $t->string('type')->nullable(); $t->string('category')->nullable(); $t->unsignedBigInteger('account_id')->nullable();
            $t->date('date')->nullable(); $t->text('comment')->nullable(); $t->unsignedBigInteger('user_id')->nullable(); $t->timestamps();
        });
    }
}

/** Норма «з техкарт» задана руками по днях їжі. */
class FakePlanBuilder extends ProductionPlanBuilder
{
    /** @var array<string, array{orders: int, grams: array, packs: array, missing: bool}> */
    private array $days = [];

    public function day(string $food, int $orders, array $grams, array $packs = [], bool $missing = false): void
    {
        $this->days[$food] = compact('orders', 'grams', 'packs', 'missing');
    }

    public function build(string $targetDate): array
    {
        $d = $this->days[$targetDate] ?? ['orders' => 0, 'grams' => [], 'packs' => [], 'missing' => false];

        return [
            'target_date'   => $targetDate,
            'orders'        => new Collection(array_fill(0, $d['orders'], null)),
            'report'        => ['__food' => $targetDate],
            'missing_plans' => $d['missing']
                ? [['plan' => (object) ['name' => 'Основний'], 'day_number' => 7, 'orders_count' => $d['orders']]]
                : [],
            'day_number'    => null,
            'debug'         => null,
        ];
    }

    public function collectIngredientGrams(array $report): array
    {
        return $this->days[$report['__food'] ?? '']['grams'] ?? [];
    }

    public function collectPackagingQty(Collection $orders, string $targetDate): array
    {
        return $this->days[$targetDate]['packs'] ?? [];
    }
}
