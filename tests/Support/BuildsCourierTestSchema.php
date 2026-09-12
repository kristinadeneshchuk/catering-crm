<?php

namespace Tests\Support;

use App\Models\Employee;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Курʼєрські таблиці поверх схеми Inbox: зміни, маршрути, пробіг, премії й
 * штрафи — і справжні міграції архіву точок, заяв та виплат.
 */
trait BuildsCourierTestSchema
{
    use BuildsInboxTestSchema;

    protected function buildCourierSchema(): void
    {
        $this->buildInboxSchema();

        Schema::create('settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email')->unique(); $t->string('password');
            $t->string('role')->default('manager'); $t->rememberToken(); $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('ant_driver_name')->nullable();
            $t->string('phone')->nullable();
            $t->string('position')->nullable();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->decimal('base_rate', 10, 2)->default(0);
            $t->decimal('balance', 10, 2)->default(0);
            $t->float('fuel_consumption')->nullable();
            $t->string('mileage_unit')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
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

        Schema::create('delivery_routes', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->string('shift')->default('all');
            $t->string('ant_route_id')->nullable();
            $t->integer('ant_route_num')->nullable();
            $t->string('driver_name')->nullable();
            $t->unsignedBigInteger('employee_id')->nullable();
            $t->string('auto_name')->nullable();
            $t->string('model_auto')->nullable();
            $t->string('registration_number')->nullable();
            $t->integer('count_comps')->default(0);
            $t->float('distance_calc')->nullable();
            $t->float('distance_fact')->nullable();
            $t->float('fuel_city')->nullable();
            $t->string('route_time_b')->nullable();
            $t->string('route_time_e')->nullable();
            $t->decimal('ant_cost_route', 10, 2)->default(0);
            $t->decimal('calculated_cost', 10, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('courier_mileage_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->date('date');
            $t->string('shift_slot')->default('full');
            $t->integer('start_km')->nullable();
            $t->integer('end_km')->nullable();
            $t->decimal('fuel_price_per_liter', 8, 2)->default(0);
            $t->float('fuel_consumption')->nullable();
            $t->string('mileage_unit')->nullable();
            $t->decimal('amort_per_km', 8, 2)->nullable();
            $t->timestamps();
        });

        foreach (['employee_bonuses', 'employee_penalties'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('employee_id');
                $t->decimal('amount', 10, 2);
                $t->text('reason');
                $t->date('date');
                $t->timestamps();
                $t->softDeletes(); // обидві моделі — SoftDeletes, як на проді
            });
        }

        (require database_path('migrations/2026_08_26_170000_create_route_stops_table.php'))->up();
        (require database_path('migrations/2026_09_12_130000_create_courier_payout_tables.php'))->up();
    }

    protected function makeCourier(array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'name' => 'Личко Володимир', 'position' => 'courier', 'base_rate' => 800,
            'balance' => 0, 'is_active' => true, 'fuel_consumption' => 8,
            'telegram_chat_id' => '777',
        ], $attrs));
    }

    protected function setting(string $key, string $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
    }
}
