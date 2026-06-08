<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();              // стабільний ключ для логіки (cook/courier/…)
            $table->string('name');                        // відображувана назва
            $table->string('color')->default('gray');      // колір бейджа
            $table->string('payment_type')->default('per_shift'); // per_shift | per_month
            $table->unsignedSmallInteger('monthly_working_days')->nullable(); // якщо per_month
            $table->boolean('split_by_brands')->default(false); // рознести по брендах в аналітиці
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->timestamps();
        });

        // Сид наявних посад зі збереженням ключів (щоб уся поточна логіка не зламалась)
        $now = now();
        DB::table('positions')->insert([
            ['key' => 'cook',    'name' => 'Кухар',         'color' => 'warning', 'sort_order' => 1,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'packer',  'name' => 'Пакувальник',   'color' => 'gray',    'sort_order' => 2,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'manager', 'name' => 'Менеджер',      'color' => 'success', 'sort_order' => 3,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'admin',   'name' => 'Адміністратор', 'color' => 'gray',    'sort_order' => 4,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'courier', 'name' => 'Кур\'єр',        'color' => 'info',    'sort_order' => 5,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'cleaner', 'name' => 'Прибиральниця',  'color' => 'gray',    'sort_order' => 6,  'payment_type' => 'per_shift', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
    }
};
