<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProposalResource;
use App\Models\AiAgent;
use App\Models\AiProposal;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderDayDish;
use App\Models\User;
use App\Services\Agents\ProposalDesk;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsInboxTestSchema;
use Tests\TestCase;

/**
 * Спільна схема й дані для тестів ШІ-агентів: замовлення Гоголя на 12–18.10,
 * агент «individual», прийоми, перевірки MenuGuard.
 */
abstract class AgentsTestCase extends TestCase
{
    use BuildsInboxTestSchema;

    protected AiAgent $agent;
    protected string $token;
    protected Order $order;
    protected int $breakfast;
    protected int $lunch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-11 12:00:00'));

        $this->buildInboxSchema();
        Schema::hasTable('users') || Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('email'); $t->string('password'); $t->string('role')->nullable();
            $t->rememberToken(); $t->timestamps();
        });
        Schema::table('dishes', function (Blueprint $t) {
            $t->text('description')->nullable(); $t->float('base_weight_g')->nullable(); $t->string('group')->nullable();
            $t->boolean('is_semi_finished')->default(false);
        });
        Schema::create('daily_menu_dishes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('daily_menu_id')->default(1); $t->unsignedBigInteger('dish_id'); $t->unsignedBigInteger('meal_type_id')->default(1);
        });
        foreach (['client_ingredient_exclusion' => 'ingredient_id', 'client_dish_exclusion' => 'dish_id', 'order_ingredient_exclusion' => 'ingredient_id'] as $tbl => $col) {
            Schema::hasTable($tbl) || Schema::create($tbl, function (Blueprint $t) use ($tbl, $col) {
                $t->id(); $t->unsignedBigInteger(str_starts_with($tbl, 'order') ? 'order_id' : 'client_id'); $t->unsignedBigInteger($col);
            });
        }
        Schema::create('meal_types', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->integer('sort_order')->default(1); $t->timestamps();
        });
        Schema::create('dish_ingredients', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('dish_id'); $t->string('type')->nullable();
            $t->unsignedBigInteger('ingredient_id')->nullable(); $t->unsignedBigInteger('child_dish_id')->nullable();
            $t->float('net_weight_g')->default(0);
        });
        Schema::create('order_day_dishes', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('order_id'); $t->date('date'); $t->unsignedBigInteger('meal_type_id');
            $t->unsignedBigInteger('dish_id'); $t->integer('weight_grams')->nullable(); $t->text('cooking_note')->nullable(); $t->timestamps();
        });
        (require database_path('migrations/2026_10_11_100000_create_ai_agents.php'))->up();
        (require database_path('migrations/2026_10_11_160000_create_ai_messages.php'))->up();
        foreach (glob(database_path('migrations/2026_10_24_1*.php')) as $m) (require $m)->up();
        Schema::hasTable('client_meal_type') || Schema::create('client_meal_type', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('client_id'); $t->unsignedBigInteger('meal_type_id');
        });
        // БЖВ продуктів — без них MenuGuard (V4) вважає продукт «битим».
        Schema::table('ingredients', function (Blueprint $t) {
            foreach (['calories_100g', 'proteins_100g', 'fats_100g', 'carbs_100g'] as $c) $t->float($c)->nullable();
        });
        // Середня ціна продукту рахується з прихідних.
        Schema::hasTable('stock_documents') || Schema::create('stock_documents', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->string('status')->nullable(); $t->timestamps();
        });
        Schema::hasTable('stock_document_items') || Schema::create('stock_document_items', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('stock_document_id'); $t->string('itemable_type'); $t->unsignedBigInteger('itemable_id');
            $t->decimal('qty', 12, 3)->default(0); $t->decimal('price', 12, 4)->default(0); $t->timestamps();
        });

        config([
            'services.telegram.bot_token' => 'test-bot',
            'services.finance.super_admin_ids' => '7',
            'services.ai_agents.approvers' => '7:111,12:222',
            'services.telegram.webhook_secret' => 'hook-secret',
        ]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 55]])]);

        $this->breakfast = DB::table('meal_types')->insertGetId(['name' => 'Сніданок', 'sort_order' => 1]);
        $this->lunch = DB::table('meal_types')->insertGetId(['name' => 'Обід', 'sort_order' => 3]);

        $catalog = $this->seedCatalog(pricePerDay: 900);
        $this->order = Order::create([
            'client_id' => $this->makeClient(['name' => 'Гоголь Денис']), 'project' => 'u_fit',
            'tariff_id' => $catalog['tariff_id'], 'calories' => 2300, 'duration' => 7,
            'start_date' => '2026-10-12', 'end_date' => '2026-10-18', 'scale_factor' => 1, 'menu_type' => 'individual',
        ]);

        // Дні доставки замовлення (MenuGuard V3: меню лише на дні замовлення).
        foreach (\Carbon\CarbonPeriod::create('2026-10-12', '2026-10-18') as $d) {
            DB::table('order_days')->insert(['order_id' => $this->order->id, 'date' => $d->toDateString()]);
        }

        $this->agent = AiAgent::create([
            'slug' => 'individual', 'name' => 'Індивідуальні раціони', 'role' => 'Меню індивідуальним клієнтам',
            'instructions' => 'Кухні — без грошей і без згадок ШІ.', 'allowed_types' => ['individual_week_menu'],
        ]);
        $this->token = $this->agent->issueToken();
    }

    protected function user(int $id, string $role = 'admin'): User
    {
        $u = new User(['name' => "u{$id}", 'email' => "u{$id}@t.local"]);
        $u->id = $id; $u->role = $role; $u->password = 'x'; $u->save();

        return $u;
    }

    protected function api(string $method, string $uri, array $data = [])
    {
        return $this->withToken($this->token)->json($method, "/api/agents/v1/{$uri}", $data);
    }

    protected function menuPayload(array $over = []): array
    {
        $existing = Dish::create(['name' => 'Омлет циклу']);

        return array_merge([
            'order_id' => $this->order->id,
            'new_dishes' => [[
                'ref' => 'hohol_chicken', 'name' => 'Інд · Курка з булгуром (Гоголь)', 'group' => 'Обіди', 'base_weight_g' => 350,
                'description' => "Технологія:\n1. Запекти курку",
                'ingredients' => [['ingredient_id' => $this->ingredient('філе куряче'), 'net_weight_g' => 150]],
            ]],
            'days' => [
                ['date' => '2026-10-12', 'meals' => [
                    ['meal_type_id' => $this->breakfast, 'dish_id' => $existing->id],
                    ['meal_type_id' => $this->lunch, 'dish_ref' => 'hohol_chicken', 'cooking_note' => 'без солі'],
                ]],
                ['date' => '2026-10-13', 'meals' => [
                    ['meal_type_id' => $this->lunch, 'dish_ref' => 'hohol_chicken', 'weight_grams' => 400],
                ]],
            ],
        ], $over);
    }

    protected function ingredient(string $name): int
    {
        return DB::table('ingredients')->where('name', $name)->value('id')
            ?? DB::table('ingredients')->insertGetId(['name' => $name, 'proteins_100g' => 20, 'fats_100g' => 3, 'carbs_100g' => 1]);
    }
}
