<?php

namespace Tests\Feature;

use App\Models\AiAgent;
use App\Models\AiProposal;
use App\Models\ClientDietProfile;
use App\Models\Dish;
use Illuminate\Support\Facades\DB;

/**
 * Кодові перевірки меню (docs/tz-ai-agents-in-crm.md §4): жорстке — 422 агенту,
 * м'яке — бейдж людині. Модель може помилитись, код — ні.
 */
class MenuGuardTest extends AgentsTestCase
{
    /** Страва з продуктів/ПФ: [ingredient_id => г] або ['pf' => dish_id]. */
    protected function dish(string $name, array $items): int
    {
        $id = Dish::create(['name' => $name])->id;
        foreach ($items as $item) {
            DB::table('dish_ingredients')->insert(isset($item['pf'])
                ? ['dish_id' => $id, 'type' => 'pf', 'child_dish_id' => $item['pf'], 'net_weight_g' => $item['g'] ?? 50]
                : ['dish_id' => $id, 'type' => 'product', 'ingredient_id' => $item['ing'], 'net_weight_g' => $item['g'] ?? 100]);
        }

        return $id;
    }

    protected function propose(array $days, ?string $token = null)
    {
        return $this->withToken($token ?? $this->token)->json('POST', '/api/agents/v1/proposals', [
            'type' => 'individual_week_menu', 'payload' => ['order_id' => $this->order->id, 'days' => $days],
        ]);
    }

    protected function lunchOn(string $date, int $dishId): array
    {
        return [['date' => $date, 'meals' => [['meal_type_id' => $this->lunch, 'dish_id' => $dishId]]]];
    }

    public function test_an_excluded_product_deep_inside_a_semi_finished_dish_is_refused(): void
    {
        $starch = $this->ingredient('крохмаль кукурудзяний');
        $sauce = $this->dish('Соус вершковий', [['ing' => $starch, 'g' => 5], ['ing' => $this->ingredient('вершки')]]);
        $base = $this->dish('Основа для запіканки', [['pf' => $sauce]]);
        $dish = $this->dish('Запіканка куряча', [['ing' => $this->ingredient('філе куряче')], ['pf' => $base]]);
        DB::table('client_ingredient_exclusion')->insert(['client_id' => $this->order->client_id, 'ingredient_id' => $starch]);

        $res = $this->propose($this->lunchOn('2026-10-14', $dish))->assertStatus(422);

        $this->assertStringContainsString('[V1] 14.10 Обід: «Запіканка куряча» містить «крохмаль кукурудзяний» (через ПФ «Основа для запіканки»)', implode("\n", $res->json('errors')));
        $this->assertSame(0, AiProposal::count());
    }

    public function test_a_replacement_in_the_order_clears_the_excluded_product(): void
    {
        $peas = $this->ingredient('горошок зелений');
        $dish = $this->dish('Курка з овочами', [['ing' => $this->ingredient('філе куряче')], ['ing' => $peas, 'g' => 40]]);
        DB::table('client_ingredient_exclusion')->insert(['client_id' => $this->order->client_id, 'ingredient_id' => $peas]);
        DB::table('order_replacements')->insert(['order_id' => $this->order->id, 'dish_id' => $dish, 'original_product_id' => $peas, 'replacement_product_id' => $this->ingredient('броколі')]);

        $this->propose($this->lunchOn('2026-10-14', $dish))->assertCreated();
    }

    public function test_a_whole_group_excluded_in_the_profile_catches_every_synonym(): void
    {
        $mexican = $this->ingredient('Овочі мексиканські');
        DB::table('ingredient_groups')->insert(['id' => 1, 'slug' => 'corn', 'name' => 'кукурудза']);
        DB::table('ingredient_group_items')->insert([['ingredient_group_id' => 1, 'ingredient_id' => $mexican], ['ingredient_group_id' => 1, 'ingredient_id' => $this->ingredient('кукурудза консервована')]]);
        ClientDietProfile::create(['client_id' => $this->order->client_id, 'excluded_groups' => ['corn']]);
        $dish = $this->dish('Телятина з овочами', [['ing' => $this->ingredient('телятина')], ['ing' => $mexican]]);

        $errors = implode("\n", $this->propose($this->lunchOn('2026-10-15', $dish))->assertStatus(422)->json('errors'));

        $this->assertStringContainsString('[V2] 15.10 Обід: «Телятина з овочами» містить «Овочі мексиканські» — у групі «кукурудза»', $errors);
    }

    public function test_a_menu_on_a_day_outside_the_order_is_refused(): void
    {
        DB::table('order_days')->where('order_id', $this->order->id)->where('date', '2026-10-16')->delete(); // пауза

        $errors = implode("\n", $this->propose($this->lunchOn('2026-10-16', $this->dish('Омлет', [['ing' => $this->ingredient('яйце')]])))->assertStatus(422)->json('errors'));

        $this->assertStringContainsString('[V3] 16.10: такого дня доставки в замовленні немає', $errors);
    }

    public function test_a_product_without_macros_is_refused_but_salt_is_fine(): void
    {
        $cherry = DB::table('ingredients')->insertGetId(['name' => 'Вишня с/м']);
        $salt = DB::table('ingredients')->insertGetId(['name' => 'Сіль']);
        $cauliflower = DB::table('ingredients')->insertGetId(['name' => 'Цвітна капуста з/м', 'calories_100g' => 328, 'proteins_100g' => 2, 'fats_100g' => 0.3, 'carbs_100g' => 4]);
        $dish = $this->dish('Сирники з вишнею', [['ing' => $this->ingredient('сир кисломолочний')], ['ing' => $cherry, 'g' => 30], ['ing' => $salt, 'g' => 1], ['ing' => $cauliflower, 'g' => 10]]);

        $errors = implode("\n", $this->propose($this->lunchOn('2026-10-14', $dish))->assertStatus(422)->json('errors'));

        $this->assertStringContainsString('«Вишня с/м» #' . $cherry . ': у довіднику немає БЖВ', $errors);
        $this->assertStringContainsString('«Цвітна капуста з/м» #' . $cauliflower . ': у картці 328 ккал/100 г, а за БЖВ 27', $errors);
        $this->assertStringNotContainsString('Сіль', $errors);
    }

    public function test_a_full_day_far_from_the_protein_target_is_refused_a_close_one_only_flagged(): void
    {
        DB::table('client_meal_type')->insert([['client_id' => $this->order->client_id, 'meal_type_id' => $this->breakfast], ['client_id' => $this->order->client_id, 'meal_type_id' => $this->lunch]]);
        // 2 × 400 г курки (20/3/1 на 100 г) під 2300 ккал → білка ≈ 414 г на день.
        $chicken = $this->dish('Курка', [['ing' => $this->ingredient('філе куряче'), 'g' => 400]]);
        $day = fn ($date) => ['date' => $date, 'meals' => [['meal_type_id' => $this->breakfast, 'dish_id' => $chicken], ['meal_type_id' => $this->lunch, 'dish_id' => $chicken]]];

        $this->order->forceFill(['target_protein_g' => 160])->saveQuietly();
        $errors = implode("\n", $this->propose([$day('2026-10-14')])->assertStatus(422)->json('errors'));
        $this->assertMatchesRegularExpression('/\[V5\] 14\.10: білки \d+ проти 160 \(\+\d+%\)/u', $errors);

        $this->order->forceFill(['target_protein_g' => 360])->saveQuietly();
        $res = $this->propose([$day('2026-10-15')])->assertCreated();
        $this->assertMatchesRegularExpression('/\[V5\] 15\.10: білки \d+ проти 360/u', implode("\n", $res->json('warnings')));
        $this->assertSame('warn', collect(AiProposal::first()->checks)->firstWhere('code', 'V5')['level']);
    }

    public function test_a_hybrid_day_with_part_of_the_meals_skips_the_nutrition_check(): void
    {
        DB::table('client_meal_type')->insert([['client_id' => $this->order->client_id, 'meal_type_id' => $this->breakfast], ['client_id' => $this->order->client_id, 'meal_type_id' => $this->lunch]]);
        $this->order->forceFill(['target_protein_g' => 160])->saveQuietly();

        $this->propose($this->lunchOn('2026-10-14', $this->dish('Курка', [['ing' => $this->ingredient('філе куряче')]])))->assertCreated();
    }

    public function test_profile_frequency_rules_are_flagged(): void
    {
        $pasta = $this->ingredient('паста');
        DB::table('ingredient_groups')->insert(['id' => 2, 'slug' => 'pasta', 'name' => 'паста/локшина']);
        DB::table('ingredient_group_items')->insert(['ingredient_group_id' => 2, 'ingredient_id' => $pasta]);
        ClientDietProfile::create(['client_id' => $this->order->client_id, 'rules' => [['kind' => 'max_per_day', 'group_slug' => 'pasta', 'value' => 1, 'text' => 'паста раз на день']]]);
        $a = $this->dish('Паста з куркою', [['ing' => $pasta], ['ing' => $this->ingredient('філе куряче')]]);
        $b = $this->dish('Локшина з овочами', [['ing' => $pasta], ['ing' => $this->ingredient('броколі')]]);

        $res = $this->propose([['date' => '2026-10-14', 'meals' => [['meal_type_id' => $this->breakfast, 'dish_id' => $a], ['meal_type_id' => $this->lunch, 'dish_id' => $b]]]])->assertCreated();

        $this->assertContains('[V9] 14.10: 2 рази — правило клієнта «паста раз на день»', $res->json('warnings'));
    }

    public function test_two_agents_cannot_hold_the_same_days_and_a_new_version_replaces_the_old(): void
    {
        $dish = $this->dish('Омлет', [['ing' => $this->ingredient('яйце')]]);
        $first = $this->propose($this->lunchOn('2026-10-14', $dish))->assertCreated()->json('id');

        $server = AiAgent::create(['slug' => 'menu', 'name' => 'Меню (сервер)', 'allowed_types' => ['individual_week_menu']]);
        $errors = implode("\n", $this->propose($this->lunchOn('2026-10-14', $dish), $server->issueToken())->assertStatus(422)->json('errors'));
        $this->assertStringContainsString("вже чекає погодження меню #{$first} від «Індивідуальні раціони»", $errors);

        $second = $this->propose($this->lunchOn('2026-10-14', $dish))->assertCreated()->json('id');
        $this->assertSame('rejected', AiProposal::find($first)->status);
        $this->assertSame([2, $first], [AiProposal::find($second)->version, AiProposal::find($second)->supersedes_id]);
    }

    public function test_a_late_menu_is_flagged_for_the_shift_lead(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13 11:00:00'));

        $res = $this->propose($this->lunchOn('2026-10-14', $this->dish('Омлет', [['ing' => $this->ingredient('яйце')]])))->assertCreated();

        $this->assertContains('[V10] Після дедлайну (10:00 напередодні): 14.10 — узгодити зі старшим зміни', $res->json('warnings'));
    }
}
