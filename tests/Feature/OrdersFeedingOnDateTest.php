<?php

namespace Tests\Feature;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Хто годується у вказану дату.
 *
 * Фасувальний лист, виробничий звіт і список покупок фільтрували замовлення
 * за статусом new/active. Для сьогодні це правильно, а для минулих дат
 * викидало все: ті замовлення давно finished. Липневий лист показував одне
 * замовлення замість сімдесяти чотирьох.
 *
 * Факт доставки — це наявність OrderDay на дату, а не поточний статус.
 */
class OrdersFeedingOnDateTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected string $past   = '2026-07-08';
    protected string $future = '2026-09-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(\Carbon\Carbon::parse('2026-08-26 12:00:00'));
        $this->buildInboxSchema();
    }

    /** Замовлення зі статусом і днем доставки. Без моделі — scope це чистий запит. */
    protected function order(string $status, string $date): int
    {
        $id = DB::table('orders')->insertGetId([
            'client_id' => 1, 'status' => $status, 'calories' => 1600,
            'duration' => 1, 'scale_factor' => 1, 'total_price' => 0, 'final_price' => 0,
        ]);

        DB::table('order_days')->insert(['order_id' => $id, 'date' => $date]);

        return $id;
    }

    protected function feeding(string $date): array
    {
        return Order::feedingOn($date)->pluck('id')->sort()->values()->all();
    }

    // --- минулі дати -------------------------------------------------------

    public function test_a_finished_order_still_counts_for_a_past_date(): void
    {
        $id = $this->order('finished', $this->past);

        // Саме через це липневий фасувальний лист показував одне замовлення.
        $this->assertSame([$id], $this->feeding($this->past));
    }

    public function test_a_paused_order_counts_for_a_past_date_too(): void
    {
        $id = $this->order('paused', $this->past);

        // Пауза сьогодні не означає, що в липні їжу не везли.
        $this->assertSame([$id], $this->feeding($this->past));
    }

    public function test_all_statuses_come_back_for_a_past_date(): void
    {
        $ids = [
            $this->order('finished', $this->past),
            $this->order('completed', $this->past),
            $this->order('paused', $this->past),
            $this->order('active', $this->past),
            $this->order('new', $this->past),
        ];
        sort($ids);

        $this->assertSame($ids, $this->feeding($this->past));
    }

    // --- сьогодні й майбутнє: поведінка не змінилась -----------------------

    public function test_a_paused_order_is_not_cooked_for_a_future_date(): void
    {
        $this->order('paused', $this->future);
        $active = $this->order('active', $this->future);

        $this->assertSame([$active], $this->feeding($this->future));
    }

    public function test_a_finished_order_is_not_cooked_for_a_future_date(): void
    {
        $this->order('finished', $this->future);

        $this->assertSame([], $this->feeding($this->future));
    }

    public function test_today_keeps_the_old_behaviour(): void
    {
        $today = now()->toDateString();

        $this->order('paused', $today);
        $active = $this->order('active', $today);
        $new    = $this->order('new', $today);

        $expected = [$active, $new];
        sort($expected);

        $this->assertSame($expected, $this->feeding($today));
    }

    // --- сама дата ---------------------------------------------------------

    public function test_an_order_without_a_day_on_that_date_is_not_included(): void
    {
        $this->order('active', $this->future);

        $this->assertSame([], $this->feeding('2026-09-11'));
    }
}
