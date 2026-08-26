<?php

namespace Tests\Feature;

use App\Filament\Pages\EmployeeAttendance;
use App\Models\Employee;
use App\Models\EmployeeShift;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Графік виходів наперед.
 *
 * Клік по табелю одразу робить increment('balance'), тож головна небезпека
 * планування — заплатити тому, хто не вийшов. Тому план тримається окремим
 * прапорцем і стає грошима лише через підтвердження дня.
 *
 * Курʼєр працює виїздами: ранок або вечір — один виїзд і одинарна ставка,
 * обидва — два виїзди і подвійна. «Половини ранку» не існує.
 */
class ShiftPlanningTest extends TestCase
{
    protected EmployeeAttendance $page;
    protected Employee $courier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildSchema();

        $this->page = new EmployeeAttendance();

        $this->courier = Employee::create([
            'name' => 'Курʼєр Тест', 'position' => 'courier',
            'base_rate' => 500, 'balance' => 0, 'is_active' => true,
        ]);
    }

    protected function buildSchema(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('position')->nullable();
            $t->string('phone')->nullable();
            $t->string('ant_driver_name')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->float('base_rate')->default(0);
            $t->float('balance')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
            $t->float('fuel_consumption')->nullable();
            $t->string('mileage_unit')->nullable();
            $t->timestamps();
        });

        Schema::create('employee_shifts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->date('date');
            $t->string('shift_slot')->default('full');
            $t->decimal('rate', 10, 2)->default(0);
            $t->boolean('is_duty')->default(false);
            $t->boolean('is_half')->default(false);
            $t->boolean('is_planned')->default(false);
            $t->timestamps();
        });

        // Employee::booted() пише історію ставок при створенні.
        Schema::create('positions', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->string('name')->nullable();
            $t->string('payment_type')->default('per_shift'); $t->timestamps();
        });

        Schema::create('rate_histories', function (Blueprint $t) {
            $t->id(); $t->string('key'); $t->float('value')->default(0);
            $t->date('effective_from')->nullable(); $t->timestamps();
        });

        Schema::create('settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });

        // Надбавки курʼєра рахуються з маршрутів — таблиці мають існувати.
        Schema::create('delivery_routes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('employee_id')->nullable();
            $t->date('date')->nullable(); $t->string('shift_slot')->nullable();
            $t->timestamps();
        });
    }

    protected function plan(string $slot, string $date): void
    {
        $this->page->setSlot($this->courier->id, $date, $slot);
    }

    protected function tomorrow(): string
    {
        return now()->addDay()->format('Y-m-d');
    }

    // --- план ---------------------------------------------------------------

    public function test_a_future_shift_does_not_touch_the_balance(): void
    {
        $this->plan(EmployeeShift::SLOT_MORNING, $this->tomorrow());

        $shift = EmployeeShift::first();

        $this->assertTrue($shift->is_planned);
        $this->assertEquals(500, $shift->rate, 'ставку показуємо одразу');
        $this->assertEquals(0, $this->courier->fresh()->balance, 'але грошей ще нема');
    }

    public function test_todays_shift_is_a_fact_and_pays_immediately(): void
    {
        $this->plan(EmployeeShift::SLOT_MORNING, now()->format('Y-m-d'));

        $this->assertFalse(EmployeeShift::first()->is_planned);
        $this->assertEquals(500, $this->courier->fresh()->balance);
    }

    public function test_two_shifts_cost_twice_as_much_as_one(): void
    {
        $this->plan(EmployeeShift::SLOT_FULL, now()->format('Y-m-d'));

        // Ранок + вечір — два виїзди.
        $this->assertEquals(1000, $this->courier->fresh()->balance);
    }

    public function test_evening_costs_the_same_as_morning(): void
    {
        $this->plan(EmployeeShift::SLOT_EVENING, now()->format('Y-m-d'));

        $this->assertEquals(500, $this->courier->fresh()->balance);
    }

    // --- зміна вибору -------------------------------------------------------

    public function test_switching_a_plan_does_not_leak_money(): void
    {
        $date = $this->tomorrow();

        $this->plan(EmployeeShift::SLOT_MORNING, $date);
        $this->plan(EmployeeShift::SLOT_FULL, $date);

        $this->assertSame(1, EmployeeShift::count(), 'на день лишається один запис');
        $this->assertEquals(0, $this->courier->fresh()->balance);
    }

    public function test_downgrading_a_fact_returns_the_difference(): void
    {
        $date = now()->format('Y-m-d');

        $this->plan(EmployeeShift::SLOT_FULL, $date);    // +1000
        $this->plan(EmployeeShift::SLOT_MORNING, $date); // -1000, +500

        $this->assertEquals(500, $this->courier->fresh()->balance);
    }

    public function test_removing_a_shift_takes_the_money_back(): void
    {
        $date = now()->format('Y-m-d');

        $this->plan(EmployeeShift::SLOT_FULL, $date);
        $this->page->setSlot($this->courier->id, $date, null);

        $this->assertSame(0, EmployeeShift::count());
        $this->assertEquals(0, $this->courier->fresh()->balance);
    }

    public function test_removing_a_plan_does_not_go_negative(): void
    {
        $date = $this->tomorrow();

        $this->plan(EmployeeShift::SLOT_FULL, $date);
        $this->page->setSlot($this->courier->id, $date, null);

        // План грошей не тримав — знімати з балансу нічого.
        $this->assertEquals(0, $this->courier->fresh()->balance);
    }

    // --- підтвердження ------------------------------------------------------

    public function test_confirming_the_day_turns_a_plan_into_money(): void
    {
        $date = $this->tomorrow();
        $this->plan(EmployeeShift::SLOT_FULL, $date);

        $this->page->confirmDay($date);

        $this->assertFalse(EmployeeShift::first()->is_planned);
        $this->assertEquals(1000, $this->courier->fresh()->balance);
    }

    public function test_confirming_twice_pays_only_once(): void
    {
        $date = $this->tomorrow();
        $this->plan(EmployeeShift::SLOT_MORNING, $date);

        $this->page->confirmDay($date);
        $this->page->confirmDay($date);

        $this->assertEquals(500, $this->courier->fresh()->balance);
    }

    public function test_confirming_does_not_touch_shifts_that_were_already_facts(): void
    {
        $today = now()->format('Y-m-d');
        $this->plan(EmployeeShift::SLOT_MORNING, $today); // одразу факт, +500

        $this->page->confirmDay($today);

        $this->assertEquals(500, $this->courier->fresh()->balance);
    }

    public function test_a_courier_who_did_not_show_up_is_removed_before_confirming(): void
    {
        $date = $this->tomorrow();
        $this->plan(EmployeeShift::SLOT_FULL, $date);

        // Менеджер знімає невихід, потім підтверджує день.
        $this->page->setSlot($this->courier->id, $date, null);
        $this->page->confirmDay($date);

        $this->assertEquals(0, $this->courier->fresh()->balance);
    }

    // --- кухня --------------------------------------------------------------

    public function test_a_cook_can_be_planned_too(): void
    {
        $cook = Employee::create([
            'name' => 'Кухар', 'position' => 'cook',
            'base_rate' => 900, 'balance' => 0, 'is_active' => true,
        ]);

        $date = $this->tomorrow();
        $this->page->toggleShift($cook->id, $date);

        $this->assertTrue(EmployeeShift::where('employee_id', $cook->id)->first()->is_planned);
        $this->assertEquals(0, $cook->fresh()->balance);

        $this->page->confirmDay($date);

        $this->assertEquals(900, $cook->fresh()->balance);
    }
}
