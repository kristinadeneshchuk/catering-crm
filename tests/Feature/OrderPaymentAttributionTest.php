<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Order;
use App\Models\Transaction;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Розподіл оплат по замовленнях.
 *
 * Було чисте FIFO: увесь гаманець клієнта гасив замовлення від старих до нових,
 * ігноруючи, до якого замовлення привязана транзакція. Через це гроші, внесені
 * на конкретне замовлення, ставили галочку на іншому.
 *
 * Стало у два проходи: спершу кожне замовлення гаситься своїми грошима, потім
 * залишок перетікає на непогашені.
 */
class OrderPaymentAttributionTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected array $catalog;

    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildInboxSchema();

        // 1000 грн/день × 6 днів = 6000 грн за замовлення.
        $this->catalog = $this->seedCatalog(pricePerDay: 1000);
        $this->client  = Client::find($this->makeClient());
    }

    /** Замовлення на 6000 грн. */
    protected function order(string $start, string $end, int $days = 6): Order
    {
        return Order::create([
            'client_id'    => $this->client->id,
            'project'      => 'afood',
            'tariff_id'    => $this->catalog['tariff_id'],
            'calories'     => 1600,
            'duration'     => $days,
            'start_date'   => $start,
            'end_date'     => $end,
            'scale_factor' => 1.0,
        ]);
    }

    protected function pay(Order $order, float $amount, string $type = 'income'): Transaction
    {
        return Transaction::create([
            'type'     => $type,
            'category' => $type === 'refund' ? 'Скасування оплати' : 'Оплата клієнта',
            'amount'   => $amount,
            'date'     => now(),
            'order_id' => $order->id,
        ]);
    }

    protected function isPaid(Order $order): bool
    {
        return (bool) $order->fresh()->is_paid;
    }

    // --- головний кейс -----------------------------------------------------

    public function test_money_marks_the_order_it_was_paid_into(): void
    {
        // Реальний випадок клієнтки Лазутіної: два замовлення, гроші внесли на
        // друге, а галочка стояла на першому.
        $first  = $this->order('2026-08-20', '2026-08-26');
        $second = $this->order('2026-08-27', '2026-09-03');

        $this->pay($second, 6000);

        $this->assertFalse($this->isPaid($first), 'Перше замовлення грошей не бачило');
        $this->assertTrue($this->isPaid($second), 'Оплатили саме друге — воно й має бути оплачене');
    }

    public function test_the_client_balance_still_shows_the_whole_debt(): void
    {
        $first  = $this->order('2026-08-20', '2026-08-26');
        $second = $this->order('2026-08-27', '2026-09-03');

        $this->pay($second, 6000);

        // Два замовлення по 6000, внесено 6000 → борг 6000.
        // Розподіл галочок на суму боргу не впливає.
        $this->assertSame(-6000.0, (float) $this->client->fresh()->balance);
    }

    // --- перетікання залишку ----------------------------------------------

    public function test_one_lump_sum_covers_several_orders(): void
    {
        // Клієнт заплатив одразу за себе і за дружину однією сумою.
        $first  = $this->order('2026-08-20', '2026-08-26');
        $second = $this->order('2026-08-27', '2026-09-03');

        $this->pay($first, 12000);

        $this->assertTrue($this->isPaid($first));
        $this->assertTrue($this->isPaid($second), 'Залишок мав перетекти на друге замовлення');
    }

    public function test_the_leftover_goes_to_the_oldest_unpaid_order(): void
    {
        $old    = $this->order('2026-08-01', '2026-08-06');
        $middle = $this->order('2026-08-10', '2026-08-15');
        $new    = $this->order('2026-08-20', '2026-08-25');

        // 9000 на найновішому: 6000 гасять його, 3000 лишаються в котлі —
        // на жодне з решти не вистачає.
        $this->pay($new, 9000);

        $this->assertTrue($this->isPaid($new));
        $this->assertFalse($this->isPaid($old));
        $this->assertFalse($this->isPaid($middle));

        // Ще 3000 — тепер вистачає на найстаріше.
        $this->pay($new, 3000);

        $this->assertTrue($this->isPaid($old), 'Залишок гасить від старих до нових');
        $this->assertFalse($this->isPaid($middle));
    }

    // --- часткові оплати ---------------------------------------------------

    public function test_a_partial_payment_does_not_mark_the_order_paid(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');

        $this->pay($order, 3000);

        $this->assertFalse($this->isPaid($order));
    }

    public function test_topping_up_the_same_order_closes_it(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');

        $this->pay($order, 3000);
        $this->pay($order, 3000);

        $this->assertTrue($this->isPaid($order));
    }

    public function test_a_partial_payment_stays_with_its_own_order(): void
    {
        $first  = $this->order('2026-08-20', '2026-08-26');
        $second = $this->order('2026-08-27', '2026-09-03');

        // По 3000 на кожне: жодне не закрите, і гроші не збираються докупи,
        // щоб закрити одне повністю.
        $this->pay($first, 3000);
        $this->pay($second, 3000);

        $this->assertFalse($this->isPaid($first));
        $this->assertFalse($this->isPaid($second));
    }

    // --- повернення --------------------------------------------------------

    public function test_a_refund_takes_the_paid_mark_back(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');

        $this->pay($order, 6000);
        $this->assertTrue($this->isPaid($order));

        $this->pay($order, 6000, 'refund');

        $this->assertFalse($this->isPaid($order));
    }

    public function test_deleting_the_payment_takes_the_paid_mark_back(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');

        $payment = $this->pay($order, 6000);
        $this->assertTrue($this->isPaid($order));

        $payment->delete();

        $this->assertFalse($this->isPaid($order));
    }

    // --- крайні випадки ----------------------------------------------------

    public function test_a_fully_discounted_order_counts_as_paid(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');
        $order->update(['discount_type' => 'percent', 'discount_value' => 100]);

        $this->assertSame(0.0, (float) $order->fresh()->final_price);
        $this->assertTrue($this->isPaid($order));
    }

    public function test_money_left_on_a_free_order_helps_the_others(): void
    {
        $free = $this->order('2026-08-10', '2026-08-15');
        $free->update(['discount_type' => 'percent', 'discount_value' => 100]);

        $paid = $this->order('2026-08-20', '2026-08-26');

        // Гроші внесли на безкоштовне замовлення — вони не мають зникнути.
        $this->pay($free, 6000);

        $this->assertTrue($this->isPaid($paid), 'Гроші з безкоштовного замовлення йдуть у спільний котел');
    }

    public function test_kopecks_do_not_break_the_comparison(): void
    {
        $order = $this->order('2026-08-20', '2026-08-26');
        $order->update(['discount_type' => 'fixed', 'discount_value' => 0.01]);

        $due = (float) $order->fresh()->final_price;
        $this->assertSame(5999.99, $due);

        $this->pay($order, $due);

        $this->assertTrue($this->isPaid($order));
    }
}
