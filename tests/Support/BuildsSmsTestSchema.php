<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Точкова схема для тестів SMS-сповіщень.
 *
 * Повний ланцюжок міграцій проєкту на чистій БД не проходить (legacy-конфлікт:
 * orders.is_paid створюється в create_orders_table і додається ще раз міграцією
 * 2026_01_29_171214), тож піднімаємо лише ті таблиці, які читає CourierSmsNotifier.
 * Міграцію sms_logs беремо справжню — щоб перевірити і її.
 */
trait BuildsSmsTestSchema
{
    protected function buildSmsSchema(): void
    {
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->text('delivery_comment')->nullable();
            $t->timestamps();
        });

        Schema::create('client_addresses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->boolean('is_default')->default(true);
            $t->string('label')->nullable();
            $t->string('street')->nullable();
            $t->timestamps();
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->string('project')->nullable();
            $t->string('status')->default('active');
            $t->string('schedule_type')->nullable();
            $t->string('delivery_time')->nullable();
            $t->timestamps();
        });

        Schema::create('order_days', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->date('date');
            $t->boolean('is_completed')->default(false);
            $t->string('delivery_time')->nullable();
            $t->date('delivery_date_override')->nullable();
            $t->decimal('extra_delivery_fee', 10, 2)->default(0);
            $t->integer('ant_route_num')->nullable();
            $t->string('ant_route_id')->nullable();
            $t->integer('ant_route_pos')->nullable();
            $t->string('ant_driver')->nullable();
            $t->string('ant_delivery_group')->nullable();
            $t->timestamps();
        });

        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('ant_driver_name')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('position')->nullable();
            $t->decimal('base_rate', 10, 2)->default(0);
            $t->decimal('balance', 10, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable();
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
            $t->string('route_time_b')->nullable();
            $t->string('route_time_e')->nullable();
            $t->decimal('ant_cost_route', 10, 2)->default(0);
            $t->decimal('calculated_cost', 10, 2)->default(0);
            $t->timestamps();
        });

        (require database_path('migrations/2026_07_28_120010_create_sms_logs_table.php'))->up();
    }

    protected function makeCourier(string $name, ?string $phone = '0671112233'): int
    {
        return DB::table('employees')->insertGetId([
            'name' => $name, 'ant_driver_name' => $name, 'phone' => $phone,
            'position' => 'courier', 'is_active' => 1,
        ]);
    }

    protected function makeRoute(array $attrs = []): int
    {
        return DB::table('delivery_routes')->insertGetId(array_merge([
            'date' => $this->deliveryDate, 'shift' => 'all',
            'ant_route_id' => 'r' . uniqid('', true), 'ant_route_num' => 1,
            'driver_name' => 'Іванов І.І.', 'employee_id' => null,
            'registration_number' => 'AA0000AA', 'count_comps' => 5,
            'route_time_b' => '05.08.2026 09:00',
        ], $attrs));
    }

    /** @return array{client_id:int, order_id:int, day_id:int} */
    protected function makeOrderDay(array $client = [], array $order = [], array $day = []): array
    {
        $clientId = DB::table('clients')->insertGetId(array_merge([
            'name' => 'Клієнт Тест', 'phone' => '0501234567',
        ], $client));

        $orderId = DB::table('orders')->insertGetId(array_merge([
            'client_id' => $clientId, 'status' => 'active', 'schedule_type' => 'every_day_morning',
        ], $order));

        $dayId = DB::table('order_days')->insertGetId(array_merge([
            'order_id' => $orderId, 'date' => $this->deliveryDate,
            'ant_route_num' => 1, 'ant_driver' => 'Іванов І.І.',
        ], $day));

        return ['client_id' => $clientId, 'order_id' => $orderId, 'day_id' => $dayId];
    }
}
