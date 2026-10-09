<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderReplacement;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Заміни клієнта переходять у нове замовлення.
 *
 * Жили лише на замовленні — при продовженні кухня готувала без замін,
 * доки менеджер не вносив їх заново.
 */
class ReplacementInheritanceTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected int $clientId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\Carbon::parse('2026-10-09 10:00:00'));
        $this->buildInboxSchema();
        $this->catalog = $this->seedCatalog(pricePerDay: 898);
        $this->clientId = $this->makeClient();
    }

    protected function order(string $start, string $end, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'client_id' => $this->clientId, 'project' => 'afood',
            'tariff_id' => $this->catalog['tariff_id'], 'calories' => 1600,
            'duration' => 5, 'start_date' => $start, 'end_date' => $end, 'scale_factor' => 1.0,
        ], $attrs));
    }

    protected function replace(Order $order, int $dishId, ?int $from = 10, ?int $to = 20): void
    {
        DB::table('order_replacements')->insert([
            'order_id' => $order->id, 'dish_id' => $dishId,
            'original_product_id' => $from, 'replacement_product_id' => $to, 'comment' => 'без цибулі',
        ]);
    }

    protected function replacementsOf(Order $order): array
    {
        return DB::table('order_replacements')->where('order_id', $order->id)
            ->orderBy('dish_id')->get(['dish_id', 'original_product_id', 'replacement_product_id', 'comment'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function test_a_renewal_gets_the_previous_order_replacements(): void
    {
        $old = $this->order('2026-09-28', '2026-10-02');
        $this->replace($old, 1);
        $this->replace($old, 2, null, null);

        $new = $this->order('2026-10-05', '2026-10-09');

        $this->assertSame($this->replacementsOf($old), $this->replacementsOf($new));
        // Старе замовлення лишилось як було.
        $this->assertCount(2, $this->replacementsOf($old));
    }

    public function test_a_concurrent_order_is_another_person_and_is_skipped(): void
    {
        $finished = $this->order('2026-09-01', '2026-09-05');
        $this->replace($finished, 7);

        $running = $this->order('2026-10-05', '2026-10-15');
        $this->replace($running, 1);

        // Друга людина в той самий період — бере не з паралельного, а з минулого.
        $second = $this->order('2026-10-06', '2026-10-10');

        $this->assertSame([7], array_column($this->replacementsOf($second), 'dish_id'));
    }

    public function test_replacements_removed_on_purpose_are_not_resurrected(): void
    {
        $older = $this->order('2026-09-01', '2026-09-05');
        $this->replace($older, 1);
        $middle = $this->order('2026-09-14', '2026-09-18');
        DB::table('order_replacements')->where('order_id', $middle->id)->delete(); // менеджер прибрав заміни

        $new = $this->order('2026-10-05', '2026-10-09');

        $this->assertSame([], $this->replacementsOf($new));
    }

    public function test_child_orders_do_not_inherit(): void
    {
        $old = $this->order('2026-09-01', '2026-09-05');
        $this->replace($old, 1);
        $parent = $this->order('2026-10-05', '2026-10-09');

        $child = $this->order('2026-10-12', '2026-10-16', ['parent_order_id' => $parent->id]);

        $this->assertSame([], $this->replacementsOf($child));
    }

    public function test_another_client_replacements_never_leak(): void
    {
        $stranger = $this->order('2026-09-01', '2026-09-05', ['client_id' => $this->makeClient(['name' => 'Інший', 'phone' => '0670000000'])]);
        $this->replace($stranger, 1);

        $this->assertSame([], $this->replacementsOf($this->order('2026-10-05', '2026-10-09')));
    }

    public function test_backfill_command_is_dry_by_default(): void
    {
        $old = $this->order('2026-09-28', '2026-10-02');
        $new = $this->order('2026-10-05', '2026-10-09');
        $new->update(['status' => 'active']);
        $this->replace($old, 1);

        $this->artisan('orders:inherit-replacements')->assertSuccessful();
        $this->assertSame([], $this->replacementsOf($new));

        $this->artisan('orders:inherit-replacements --apply')->assertSuccessful();
        $this->assertCount(1, $this->replacementsOf($new));
    }

    // --- правка в одному живому замовленні доходить до іншого ---------------

    protected function edit(Order $order, int $dishId, ?int $to): OrderReplacement
    {
        return OrderReplacement::updateOrCreate(
            ['order_id' => $order->id, 'dish_id' => $dishId, 'original_product_id' => 10],
            ['replacement_product_id' => $to, 'comment' => 'правка'],
        );
    }

    public function test_an_edit_in_the_renewal_reaches_the_current_order(): void
    {
        $current = $this->order('2026-10-05', '2026-10-09');
        $renewal = $this->order('2026-10-12', '2026-10-16');

        $this->edit($renewal, 3, 30);

        $this->assertSame([30], array_column($this->replacementsOf($current), 'replacement_product_id'));
    }

    public function test_an_edit_and_a_reset_in_the_current_order_reach_the_renewal(): void
    {
        $current = $this->order('2026-10-05', '2026-10-09');
        $renewal = $this->order('2026-10-12', '2026-10-16');

        $rep = $this->edit($current, 3, 30);
        $this->edit($current, 3, 31);
        $this->assertSame([31], array_column($this->replacementsOf($renewal), 'replacement_product_id'));

        $rep->delete();
        $this->assertSame([], $this->replacementsOf($renewal));
    }

    public function test_family_orders_are_not_synced(): void
    {
        $mine  = $this->order('2026-10-05', '2026-10-15');
        $wifes = $this->order('2026-10-06', '2026-10-10');

        $this->edit($mine, 3, 30);

        $this->assertSame([], $this->replacementsOf($wifes));
    }

    public function test_finished_orders_are_left_alone(): void
    {
        $past = $this->order('2026-09-01', '2026-09-05');
        $past->update(['status' => 'finished']);
        $current = $this->order('2026-10-05', '2026-10-09');

        $this->edit($current, 3, 30);
        $this->assertSame([], $this->replacementsOf($past));

        // І правка в завершеному не тягнеться в живі.
        $this->edit($past, 4, 40);
        $this->assertSame([3], array_column($this->replacementsOf($current), 'dish_id'));
    }
}
