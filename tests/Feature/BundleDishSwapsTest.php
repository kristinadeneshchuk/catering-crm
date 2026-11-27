<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\ReplacementBundle;
use App\Services\Orders\BundleDishSwaps;
use App\Support\ReplacementScale;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Шаблон замін зі стравами («Спорт»): прив'язали до клієнта — заміни страв
 * самі стають у його живі циклові замовлення і так само знімаються.
 */
class BundleDishSwapsTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected int $clientId;

    protected int $bundleId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\Carbon::parse('2026-11-28 10:00:00'));
        $this->buildInboxSchema();
        $this->catalog = $this->seedCatalog(pricePerDay: 898);
        $this->clientId = $this->makeClient();

        $this->bundleId = DB::table('replacement_bundles')->insertGetId(['name' => 'Спорт']);
        $this->rule(461, 779);
        $this->rule(826, 998);
    }

    protected function rule(int $from, int $to): void
    {
        DB::table('replacement_bundle_items')->insert([
            'bundle_id' => $this->bundleId, 'original_dish_id' => $from, 'replacement_dish_id' => $to,
        ]);
    }

    protected function attach(): void
    {
        DB::table('client_replacement_bundle')->insert(['client_id' => $this->clientId, 'replacement_bundle_id' => $this->bundleId]);
    }

    protected function order(string $start, string $end, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'client_id' => $this->clientId, 'project' => 'afood',
            'tariff_id' => $this->catalog['tariff_id'], 'calories' => 2200,
            'duration' => 5, 'start_date' => $start, 'end_date' => $end, 'scale_factor' => 1.0,
        ], $attrs));
    }

    protected function swapsOf(Order $order): array
    {
        return DB::table('order_replacements')->where('order_id', $order->id)->whereNull('original_product_id')
            ->orderBy('dish_id')->pluck('replacement_dish_id', 'dish_id')->all();
    }

    protected function sync(): int
    {
        return app(BundleDishSwaps::class)->syncClient(Client::find($this->clientId));
    }

    public function test_attaching_the_bundle_puts_dish_swaps_into_live_orders(): void
    {
        $order = $this->order('2026-11-30', '2026-12-04');
        $this->assertSame([], $this->swapsOf($order));

        $this->attach();
        $this->assertSame(2, $this->sync());

        $this->assertSame([461 => 779, 826 => 998], $this->swapsOf($order));
        $this->assertSame('Пакет: Спорт', DB::table('order_replacements')->where('order_id', $order->id)->value('comment'));
        $this->assertSame(0, $this->sync()); // повторний запуск нічого не дублює
    }

    public function test_a_new_order_of_a_client_with_the_bundle_gets_swaps_at_once(): void
    {
        $this->attach();

        $order = $this->order('2026-11-30', '2026-12-04');

        $this->assertSame([461 => 779, 826 => 998], $this->swapsOf($order));
    }

    public function test_detaching_the_bundle_removes_only_its_own_swaps(): void
    {
        $this->attach();
        $order = $this->order('2026-11-30', '2026-12-04');
        DB::table('order_replacements')->insert([
            'order_id' => $order->id, 'dish_id' => 500, 'replacement_dish_id' => 501, 'comment' => 'не любить',
        ]);

        DB::table('client_replacement_bundle')->delete();
        $this->sync();

        $this->assertSame([500 => 501], $this->swapsOf($order));
    }

    public function test_a_manual_swap_of_the_same_dish_wins(): void
    {
        $order = $this->order('2026-11-30', '2026-12-04');
        DB::table('order_replacements')->insert([
            'order_id' => $order->id, 'dish_id' => 461, 'replacement_dish_id' => 700, 'comment' => 'просив сам',
        ]);

        $this->attach();
        $this->sync();

        $this->assertSame([461 => 700, 826 => 998], $this->swapsOf($order));
    }

    public function test_a_changed_rule_reaches_the_order_and_finished_orders_stay(): void
    {
        $this->attach();
        $done = $this->order('2026-11-02', '2026-11-06');
        DB::table('orders')->where('id', $done->id)->update(['status' => 'finished']);
        $live = $this->order('2026-11-30', '2026-12-04');

        DB::table('replacement_bundle_items')->where('original_dish_id', 461)->update(['replacement_dish_id' => 687]);
        DB::table('replacement_bundle_items')->where('original_dish_id', 826)->delete();
        $this->sync();

        $this->assertSame([461 => 687], $this->swapsOf($live));
        $this->assertSame([461 => 779, 826 => 998], $this->swapsOf($done));
    }

    public function test_individual_orders_are_left_alone(): void
    {
        $this->attach();

        $order = $this->order('2026-11-30', '2026-12-04', ['menu_type' => 'individual']);

        $this->assertSame([], $this->swapsOf($order));
    }

    public function test_deleting_the_bundle_clears_its_swaps(): void
    {
        $this->attach();
        $order = $this->order('2026-11-30', '2026-12-04');

        ReplacementBundle::find($this->bundleId)->delete();

        $this->assertSame([], $this->swapsOf($order));
    }

    public function test_ingredient_rules_and_dish_swaps_do_not_mix(): void
    {
        DB::table('replacement_bundle_items')->insert([
            'bundle_id' => $this->bundleId, 'original_ingredient_id' => 10, 'replacement_ingredient_id' => 20,
        ]);
        $bundle = ReplacementBundle::find($this->bundleId);

        $this->assertSame([10], $bundle->items->pluck('original_ingredient_id')->all());
        $this->assertSame([461, 826], $bundle->dishSwaps->pluck('original_dish_id')->sort()->values()->all());
    }

    public function test_replacement_recipe_is_scaled_to_the_same_portion_weight(): void
    {
        $cycle = (object) ['base_weight_g' => 1000];
        $ind   = (object) ['base_weight_g' => 306];

        // Порція 376 г: масштаб від циклової бази 0.376 → техкарта заміни ×1.2288 = ті самі 376 г.
        $this->assertEqualsWithDelta(376 / 306, ReplacementScale::for($cycle, $ind, 0.376), 0.0001);
        $this->assertSame(0.376, ReplacementScale::for($cycle, (object) ['base_weight_g' => 0], 0.376));
    }
}
