<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Inbox питає, хто везе замовлення: позиція в маршруті й курʼєр з його чатом.
 */
class InboxRouteStopTest extends TestCase
{
    use BuildsInboxTestSchema;

    private int $orderId;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');
        $this->buildInboxSchema();

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->unsignedBigInteger('inbox_conversation_id')->nullable();
            $t->timestamps();
        });
        Schema::create('route_stops', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('shift', 16)->nullable();
            $t->string('ant_route_id')->nullable();
            $t->unsignedInteger('position')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->string('driver_name')->nullable();
            $t->string('courier_name')->nullable();
            $t->string('courier_phone', 32)->nullable();
            $t->string('car_number', 32)->nullable();
            $t->string('address')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->timestamps();
        });

        $this->orderId = DB::table('orders')->insertGetId(['client_id' => $this->makeClient(), 'status' => 'active']);
        DB::table('employees')->insert(['id' => 54, 'name' => 'Щенніков Юрій', 'phone' => '+380508796902', 'inbox_conversation_id' => 1105]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stop(array $attrs): void
    {
        DB::table('route_stops')->insert(array_merge([
            'date' => '2026-10-27', 'shift' => 'evening', 'ant_route_id' => 'R1', 'employee_id' => 54,
            'courier_name' => 'Щенніков Юрій', 'courier_phone' => '+380508796902', 'car_number' => 'КА6041ВІ',
        ], $attrs));
    }

    public function test_it_returns_the_courier_and_position_in_the_route(): void
    {
        $this->stop(['position' => 10, 'order_id' => $this->orderId, 'address' => 'вул. Тестова, 1']);
        foreach (range(1, 11) as $p) {
            if ($p !== 10) $this->stop(['position' => $p]);
        }
        $this->stop(['position' => 1, 'ant_route_id' => 'R2', 'employee_id' => 9]); // чужий маршрут

        $this->getJson("/api/inbox/v1/orders/{$this->orderId}/route-stop?date=2026-10-27", $this->authHeaders())
            ->assertOk()
            ->assertExactJson(['data' => [
                'date' => '2026-10-27', 'shift' => 'evening', 'position' => 10, 'stops_total' => 11,
                'address' => 'вул. Тестова, 1',
                'courier' => [
                    'employee_id' => 54, 'name' => 'Щенніков Юрій', 'phone' => '+380508796902',
                    'car_number' => 'КА6041ВІ', 'inbox_conversation_id' => 1105,
                ],
            ]]);
    }

    public function test_it_picks_the_shift_closest_to_now_and_defaults_to_today_in_kyiv(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 07:30', 'Europe/Kyiv'));
        $this->stop(['position' => 3, 'order_id' => $this->orderId, 'shift' => 'evening']);
        $this->stop(['position' => 5, 'order_id' => $this->orderId, 'shift' => 'morning', 'ant_route_id' => 'M1']);

        $this->getJson("/api/inbox/v1/orders/{$this->orderId}/route-stop", $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.shift', 'morning')
            ->assertJsonPath('data.position', 5);
    }

    public function test_it_returns_404_when_the_order_is_not_routed_that_day(): void
    {
        $this->stop(['position' => 1, 'order_id' => $this->orderId, 'date' => '2026-10-26']);

        $this->getJson("/api/inbox/v1/orders/{$this->orderId}/route-stop?date=2026-10-27", $this->authHeaders())
            ->assertNotFound();
    }

    public function test_it_requires_the_service_token(): void
    {
        $this->getJson("/api/inbox/v1/orders/{$this->orderId}/route-stop")->assertUnauthorized();
    }
}
