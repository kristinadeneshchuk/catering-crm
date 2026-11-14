<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Services\Kitchen\IndividualMenuGaps;
use App\Services\Kitchen\IndividualPacking;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Індивідуальне замовлення без прописаних страв.
 *
 * 12.11.2026 замовлення з меню видалили й створили заново на ті самі дні.
 * Страви стерлись каскадом, нове замовлення лишилось порожнім і не потрапило
 * ні у фасування циклічного (індивідуальні там пропускались), ні в блок
 * індивідуальних (туди беруться лише замовлення зі стравами). Кухня дізналась
 * з етикетки й доготовлювала.
 */
class IndividualMenuGapsTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-13 12:00:00'));
        $this->buildInboxSchema();

        Schema::create('order_day_dishes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->date('date');
            $t->unsignedBigInteger('meal_type_id');
            $t->unsignedBigInteger('dish_id');
            $t->unsignedSmallInteger('weight_grams')->nullable();
            $t->string('cooking_note')->nullable();
            $t->timestamps();
        });
    }

    protected function order(string $menuType, array $days, string $status = 'active'): int
    {
        $id = DB::table('orders')->insertGetId([
            'client_id' => $this->makeClient(), 'status' => $status, 'calories' => 1800, 'menu_type' => $menuType,
            'duration' => count($days), 'scale_factor' => 1, 'total_price' => 0, 'final_price' => 0,
        ]);
        foreach ($days as $date) {
            DB::table('order_days')->insert(['order_id' => $id, 'date' => $date]);
        }

        return $id;
    }

    protected function dish(int $orderId, string $date): void
    {
        DB::table('order_day_dishes')->insert(['order_id' => $orderId, 'date' => $date, 'meal_type_id' => 9, 'dish_id' => 1]);
    }

    public function test_finds_individual_days_without_dishes_only(): void
    {
        $empty  = $this->order('individual', ['2026-11-14', '2026-11-15']);
        $half   = $this->order('individual', ['2026-11-14', '2026-11-15']);
        $this->dish($half, '2026-11-14');
        $cyclic = $this->order('cyclic', ['2026-11-14']);
        $done   = $this->order('individual', ['2026-11-14'], 'finished');

        $gaps = app(IndividualMenuGaps::class)->between(Carbon::parse('2026-11-14'), Carbon::parse('2026-11-16'));

        $this->assertSame(
            [[$empty, '2026-11-14'], [$empty, '2026-11-15'], [$half, '2026-11-15']],
            $gaps->sortBy(['date', 'order_id'])->map(fn ($r) => [(int) $r->order_id, $r->date])->values()->all(),
        );
    }

    public function test_report_is_null_without_gaps_and_lists_orders_otherwise(): void
    {
        $svc = app(IndividualMenuGaps::class);
        $this->assertNull($svc->report(Carbon::parse('2026-11-14'), Carbon::parse('2026-11-16')));

        $id = $this->order('individual', ['2026-11-14', '2026-11-15']);
        $text = $svc->report(Carbon::parse('2026-11-14'), Carbon::parse('2026-11-16'));

        $this->assertStringContainsString('замовлення №'.$id, $text);
        $this->assertStringContainsString('14.11, 15.11', $text);
    }

    public function test_order_with_upcoming_personal_menu_cannot_be_deleted(): void
    {
        $id = $this->order('individual', ['2026-11-14']);
        $this->dish($id, '2026-11-14');

        $this->assertFalse(Order::find($id)->delete());
        $this->assertNotNull(Order::find($id));
        $this->assertSame(1, DB::table('order_day_dishes')->where('order_id', $id)->count());
    }

    public function test_order_with_only_past_menu_or_no_menu_can_be_deleted(): void
    {
        $past = $this->order('individual', ['2026-11-10']);
        $this->dish($past, '2026-11-10');
        $bare = $this->order('individual', ['2026-11-14']);

        $this->assertTrue(Order::find($past)->delete());
        $this->assertTrue(Order::find($bare)->delete());
    }

    public function test_packing_treats_individual_without_dishes_on_date_as_cyclic(): void
    {
        $with    = $this->order('individual', ['2026-11-14']);
        $this->dish($with, '2026-11-14');
        $without = $this->order('individual', ['2026-11-14']);
        $other   = $this->order('individual', ['2026-11-14']);
        $this->dish($other, '2026-11-15'); // меню є, але на інший день

        $packing = new IndividualPacking(Order::with('client')->whereIn('id', [$with, $without, $other])->get(), '2026-11-14');

        $this->assertTrue($packing->has($with));
        $this->assertFalse($packing->has($without));
        $this->assertFalse($packing->has($other));
    }
}
