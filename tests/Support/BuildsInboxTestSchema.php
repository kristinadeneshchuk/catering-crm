<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Точкова схема для тестів Inbox API.
 *
 * Повний ланцюжок міграцій на чистій БД не проходить (legacy-конфлікт
 * orders.is_paid), тож піднімаємо руками те, що читає і пише API — разом із
 * таблицями, які чіпають обсервери (Order → KitchenNotification, Transaction).
 * Моделі тут працюють по-справжньому: нам важливо перевірити саме Order::booted
 * з його розрахунком ціни, а не обійти його.
 */
trait BuildsInboxTestSchema
{
    protected function buildInboxSchema(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->string('logo')->nullable();
            $t->string('color')->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('recipient_name')->nullable();
            $t->string('iban')->nullable();
            $t->string('tax_id')->nullable();
            $t->string('bank_name')->nullable();
            $t->string('mfo')->nullable();
            $t->string('payment_purpose')->nullable();
            $t->timestamps();
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->unsignedInteger('sequence');
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('project')->nullable();
            $t->date('issued_on');
            $t->decimal('amount', 10, 2);
            $t->string('purpose', 500)->nullable();
            $t->text('requisites')->nullable();
            $t->string('token')->unique();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('calorie_ranges', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('min_kcal');
            $t->integer('max_kcal');
            $t->timestamps();
        });

        Schema::create('tariffs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('default_menu_plan_id')->nullable();
            $t->string('name');
            $t->unsignedSmallInteger('min_days')->nullable();
            $t->string('project')->nullable();
            $t->integer('calories')->default(0);
            $t->unsignedBigInteger('calorie_range_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::create('tariff_prices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tariff_id');
            $t->unsignedBigInteger('calorie_range_id');
            $t->decimal('price_per_day', 10, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->string('email')->nullable();
            $t->string('sales_source')->nullable();
            $t->string('instagram_url')->nullable();
            $t->string('telegram_username')->nullable();
            $t->string('facebook_url')->nullable();
            $t->integer('target_kcal')->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->string('address')->nullable();
            $t->string('address_entrance')->nullable();
            $t->string('address_apartment')->nullable();
            $t->string('address_floor')->nullable();
            $t->text('delivery_comment')->nullable();
            $t->text('production_comment')->nullable();
            $t->text('menu_brief')->nullable();
            $t->text('allergies')->nullable();
            $t->text('manager_comment')->nullable();
            $t->string('ant_comp_id')->nullable();
            $t->string('cabinet_token')->nullable();
            $t->boolean('has_cutlery')->default(false);
            $t->string('water_option')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
        });

        Schema::create('client_addresses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->string('label')->nullable();
            $t->string('address')->nullable();
            $t->string('lat')->nullable();
            $t->string('lng')->nullable();
            $t->string('address_entrance')->nullable();
            $t->string('address_apartment')->nullable();
            $t->string('address_floor')->nullable();
            $t->text('delivery_comment')->nullable();
            $t->boolean('is_default')->default(false);
            $t->string('ant_comp_id')->nullable();
            $t->timestamps();
        });

        Schema::create('client_channels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('channel');
            $t->string('project')->nullable();
            $t->string('external_id');
            $t->string('username')->nullable();
            $t->string('display_name')->nullable();
            $t->string('avatar_url')->nullable();
            $t->text('raw_meta')->nullable();
            $t->timestamps();
            $t->unique(['channel', 'external_id']);
        });

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('parent_order_id')->nullable();
            $t->unsignedBigInteger('client_id');
            $t->string('project')->nullable();
            $t->unsignedBigInteger('tariff_id')->nullable();
            $t->integer('calories')->default(0);
            $t->integer('target_protein_g')->nullable();
            $t->integer('target_fats_g')->nullable();
            $t->integer('target_carbs_g')->nullable();
            $t->integer('duration')->default(0);
            $t->double('scale_factor')->default(1);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->decimal('total_price', 10, 2)->default(0);
            $t->decimal('price_per_day', 10, 2)->nullable();
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 10, 2)->nullable();
            $t->string('discount_reason')->nullable();
            $t->decimal('discount_amount', 10, 2)->default(0);
            $t->decimal('final_price', 10, 2)->default(0);
            $t->boolean('is_paid')->default(false);
            $t->string('status')->nullable();
            $t->string('source', 32)->nullable();
            $t->string('schedule_type')->nullable();
            $t->string('menu_type')->default('cyclic');
            $t->unsignedBigInteger('menu_plan_id')->nullable();
            $t->string('delivery_time')->nullable();
            $t->text('comment')->nullable();
            $t->string('menu_token')->nullable();
            $t->boolean('reward_unlocked')->default(false);
            $t->boolean('reward_given')->default(false);
            $t->timestamps();
        });

        Schema::create('order_days', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->date('date');
            $t->boolean('is_completed')->default(false);
            $t->string('address')->nullable();
            $t->string('address_entrance')->nullable();
            $t->string('address_apartment')->nullable();
            $t->string('address_floor')->nullable();
            $t->text('delivery_comment')->nullable();
            $t->string('delivery_time')->nullable();
            $t->date('delivery_date_override')->nullable();
            $t->decimal('extra_delivery_fee', 10, 2)->default(0);
            $t->string('discount_type')->nullable();
            $t->decimal('discount_value', 10, 2)->nullable();
            $t->decimal('discount_amount', 10, 2)->default(0);
            $t->integer('fake_kcal')->nullable();
            $t->integer('fake_prot')->nullable();
            $t->integer('fake_fat')->nullable();
            $t->integer('fake_carb')->nullable();
            $t->integer('ant_route_num')->nullable();
            $t->string('ant_route_id')->nullable();
            $t->integer('ant_route_pos')->nullable();
            $t->string('ant_driver')->nullable();
            $t->string('ant_delivery_group')->nullable();
            $t->timestamps();
        });

        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->string('type');
            $t->string('category')->nullable();
            $t->decimal('amount', 12, 2)->default(0);
            $t->date('date')->nullable();
            $t->text('comment')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->decimal('balance', 12, 2)->default(0);
            $t->timestamps();
        });

        Schema::create('kitchen_notifications', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('client_name')->nullable();
            $t->integer('calories')->nullable();
            $t->string('schedule_type')->nullable();
            $t->string('project')->nullable();
            $t->boolean('has_exclusions')->default(false);
            $t->integer('duration')->nullable();
            $t->date('start_date')->nullable();
            $t->text('message')->nullable();
            $t->boolean('is_read')->default(false);
            $t->timestamps();
        });

        Schema::create('order_calls', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('order_id');
            $t->unsignedBigInteger('client_id')->nullable();
            $t->string('status')->default('new');
            $t->text('comment')->nullable();
            $t->timestamp('next_call_at')->nullable();
            $t->string('refusal_reason')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });

        Schema::create('menu_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
        });

        // Довідники під виключення клієнта та замовлення.
        Schema::create('ingredients', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        Schema::create('dishes', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        foreach ([
            'client_ingredient_exclusion' => ['client_id', 'ingredient_id'],
            'client_dish_exclusion'       => ['client_id', 'dish_id'],
            'order_ingredient_exclusion'  => ['order_id', 'ingredient_id'],
        ] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->id();
                foreach ($columns as $column) {
                    $t->unsignedBigInteger($column);
                }
                $t->timestamps();
            });
        }

        Schema::create('replacement_bundles', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->timestamps();
        });

        Schema::create('client_replacement_bundle', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('replacement_bundle_id');
            $t->timestamps();
        });

        Schema::create('replacement_bundle_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('replacement_bundle_id');
            $t->unsignedBigInteger('original_ingredient_id')->nullable();
            $t->unsignedBigInteger('replacement_ingredient_id')->nullable();
            $t->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->string('log_name')->nullable();
            $t->text('description')->nullable();
            $t->nullableMorphs('subject', 'subject');
            $t->nullableMorphs('causer', 'causer');
            $t->string('event')->nullable();
            $t->text('properties')->nullable();
            $t->uuid('batch_uuid')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Мінімальний прайс: бренд + тариф + діапазон + ціна за день.
     *
     * @return array{project_id:int, tariff_id:int, range_id:int}
     */
    protected function seedCatalog(
        string $slug = 'afood',
        string $tariffName = 'Від 1 дня',
        float $pricePerDay = 1000,
        ?int $minDays = null,
    ): array {
        $projectId = DB::table('projects')->insertGetId([
            'slug' => $slug, 'name' => strtoupper($slug), 'is_active' => 1,
        ]);

        // Діапазон спільний для всіх брендів — як у бойовій базі. Створювати
        // на кожен бренд свій означало б отримати перекриття, якого в CRM нема.
        $rangeId = DB::table('calorie_ranges')->where('min_kcal', 1499)->value('id')
            ?: DB::table('calorie_ranges')->insertGetId([
                'name' => 'Normal 1600-1700 ккал', 'min_kcal' => 1499, 'max_kcal' => 1700,
            ]);

        $tariffId = DB::table('tariffs')->insertGetId([
            'name' => $tariffName, 'project' => $slug, 'is_active' => 1, 'min_days' => $minDays,
        ]);

        DB::table('tariff_prices')->insert([
            'tariff_id' => $tariffId, 'calorie_range_id' => $rangeId, 'price_per_day' => $pricePerDay,
        ]);

        return ['project_id' => $projectId, 'tariff_id' => $tariffId, 'range_id' => $rangeId];
    }

    protected function makeClient(array $attrs = []): int
    {
        return DB::table('clients')->insertGetId(array_merge([
            'name' => 'Тестовий Клієнт', 'phone' => '0955532677', 'balance' => 0,
        ], $attrs));
    }

    /** Заголовки з валідним сервісним токеном. */
    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer '.config('services.inbox.token')];
    }
}
