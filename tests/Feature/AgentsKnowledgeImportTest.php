<?php

namespace Tests\Feature;

use App\Models\ClientDietProfile;
use App\Models\IngredientGroup;
use Illuminate\Support\Facades\DB;

/** Перенесення знань з теки на Маку в CRM: групи-синоніми й профілі харчування. */
class AgentsKnowledgeImportTest extends AgentsTestCase
{
    public function test_ingredient_groups_are_shown_first_and_written_only_with_the_flag(): void
    {
        $starch = $this->ingredient('крахмал кукурузный');
        $mexican = $this->ingredient('Овочі мексиканські с/м');
        $this->ingredient('Суміш перців горошок');
        $peas = $this->ingredient('Зелений горошок с/м');

        $this->artisan('agents:ingredient-groups', ['--group' => 'кукурудза'])
            ->expectsOutputToContain('(Кукурудза) — 2:')
            ->assertSuccessful();
        $this->assertSame(0, IngredientGroup::count());

        $this->artisan('agents:ingredient-groups', ['--write' => true])->assertSuccessful();
        $map = IngredientGroup::map();
        $this->assertEqualsCanonicalizing([$starch, $mexican], $map['кукурудза']);
        $this->assertSame([$peas], $map['горошок']); // «перців горошок» — спеція, не горох
    }

    public function test_profiles_from_the_mac_folder_are_imported_after_a_dry_run(): void
    {
        $cid = $this->order->client_id;
        $this->order->forceFill(['status' => 'active', 'target_protein_g' => 200])->saveQuietly();
        $file = storage_path('framework/testing/profiles.json');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, json_encode([
            ['slug' => 'hohol-denys', 'client_id' => $cid, 'mode' => 'hybrid', 'macros' => ['p' => 240, 'f' => 60, 'c' => 210],
                'stop' => ['лактоза', 'смажене'], 'avoid' => ['риба_біла'], 'want_more' => [], 'liked_dishes' => ['сирники (21.09)'],
                'cooking' => ['rules' => ['курка соковита'], 'salt' => 'мінімум'], 'format' => ['соус окремо'], 'open_questions' => ['Ціль?']],
            ['slug' => 'hohol-wife', 'client_id' => $cid, 'stop' => []],
        ], JSON_UNESCAPED_UNICODE));

        $this->artisan('agents:import-profiles', ['path' => $file])
            ->expectsOutputToContain('hohol-wife: клієнт #' . $cid . ' уже має профіль «hohol-denys» (пара/родина?)')
            ->expectsOutputToContain('цілі БЖВ: у профілі 240/60/210, у замовленні #' . $this->order->id . ' 200/—/— — не змінюю')
            ->assertSuccessful();
        $this->assertSame(0, ClientDietProfile::count());

        $this->artisan('agents:import-profiles', ['path' => $file, '--write' => true])->assertSuccessful();
        $p = ClientDietProfile::sole();
        $this->assertSame(['hybrid', ['лактоза'], [['group_slug' => 'риба_біла', 'text' => 'риба_біла']], ['сирники (21.09)']],
            [$p->mode, $p->excluded_groups, $p->avoid, $p->want_more]);
        $this->assertSame("Сіль: мінімум\nПодача: соус окремо", $p->kitchen_memo);
        $this->assertStringContainsString('Не можна (без групи, перевірити виключення): смажене', $p->notes);
        $this->assertSame(200, (int) DB::table('orders')->where('id', $this->order->id)->value('target_protein_g'));
    }
}
