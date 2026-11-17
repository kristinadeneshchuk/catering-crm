<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Клієнт, створений не через форму адмінки, приходить без прийомів їжі.
 * Без них розрахунок раціону порожній: клієнта немає у виробничому та
 * фасувальному листах, а на наліпці — порожнє меню.
 */
class ClientMealTypesDefaultTest extends TestCase
{
    use BuildsInboxTestSchema;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.inbox.token', 'test-service-token');
        $this->buildInboxSchema();

        Schema::create('meal_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('sort_order')->default(0);
            $t->timestamps();
        });
        Schema::create('meal_plans', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->integer('min_kcal');
            $t->integer('max_kcal');
            $t->timestamps();
        });
        Schema::create('meal_plan_meal_type', function (Blueprint $t) {
            $t->unsignedBigInteger('meal_plan_id');
            $t->unsignedBigInteger('meal_type_id');
        });
        Schema::create('client_meal_type', function (Blueprint $t) {
            $t->unsignedBigInteger('client_id');
            $t->unsignedBigInteger('meal_type_id');
        });

        foreach (['Сніданок', 'Перекус', 'Обід', 'Полуденок', 'Вечеря', 'Додаток'] as $i => $name) {
            DB::table('meal_types')->insert(['id' => $i + 1, 'name' => $name, 'sort_order' => $i + 1]);
        }
        // 1557 ккал — число, якого немає в інших тестах: findForKcal кешує статично.
        DB::table('meal_plans')->insert(['id' => 1, 'name' => 'до 1599', 'min_kcal' => 1500, 'max_kcal' => 1599]);
        foreach ([1, 2, 3, 4, 5] as $mt) {
            DB::table('meal_plan_meal_type')->insert(['meal_plan_id' => 1, 'meal_type_id' => $mt]);
        }
    }

    private function createOrder(int $clientId, array $ids): void
    {
        $this->postJson('/api/inbox/v1/orders', [
            'project_id'      => $ids['project_id'],
            'client_id'       => $clientId,
            'tariff_id'       => $ids['tariff_id'],
            'calories'        => 1557,
            'days'            => 5,
            'start_date'      => '2026-08-17',
            'delivery_window' => 'morning',
            'source'          => 'telegram_inbox',
        ], $this->authHeaders())->assertStatus(201);
    }

    public function test_a_client_without_meals_gets_the_set_for_the_order_calories(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 898);
        $clientId = $this->makeClient();

        $this->createOrder($clientId, $ids);

        $this->assertSame(
            [1, 2, 3, 4, 5],
            DB::table('client_meal_type')->where('client_id', $clientId)->orderBy('meal_type_id')->pluck('meal_type_id')->all(),
        );
    }

    public function test_meals_already_chosen_for_the_client_are_left_alone(): void
    {
        $ids      = $this->seedCatalog(pricePerDay: 898);
        $clientId = $this->makeClient();
        DB::table('client_meal_type')->insert([
            ['client_id' => $clientId, 'meal_type_id' => 1],
            ['client_id' => $clientId, 'meal_type_id' => 3],
        ]);

        $this->createOrder($clientId, $ids);

        $this->assertSame(
            [1, 3],
            DB::table('client_meal_type')->where('client_id', $clientId)->orderBy('meal_type_id')->pluck('meal_type_id')->all(),
        );
    }
}
