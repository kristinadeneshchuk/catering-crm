<?php

namespace Tests\Feature;

use App\Models\ClientDietProfile;
use App\Services\Agents\ProfileExclusionSync;
use Illuminate\Support\Facades\DB;

/**
 * Групи «не можна» з профілю розгортаються у виключення продуктів з позначкою
 * source=profile_group; ручні рядки не чіпаємо, свої зайві прибираємо.
 */
class ProfileExclusionSyncTest extends AgentsTestCase
{
    private int $corn;
    private int $mexican;
    private int $rice;
    private int $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = (int) DB::table('clients')->insertGetId(['name' => 'Тест', 'created_at' => now(), 'updated_at' => now()]);
        $this->corn = (int) DB::table('ingredients')->insertGetId(['name' => 'кукурудза консервована', 'created_at' => now(), 'updated_at' => now()]);
        $this->mexican = (int) DB::table('ingredients')->insertGetId(['name' => 'мексиканська суміш', 'created_at' => now(), 'updated_at' => now()]);
        $this->rice = (int) DB::table('ingredients')->insertGetId(['name' => 'рис басматі', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ingredient_groups')->insert([
            ['id' => 1, 'slug' => 'кукурудза', 'name' => 'Кукурудза'],
            ['id' => 2, 'slug' => 'крупи', 'name' => 'Крупи (каші)'],
        ]);
        DB::table('ingredient_group_items')->insert([
            ['ingredient_group_id' => 1, 'ingredient_id' => $this->corn],
            ['ingredient_group_id' => 1, 'ingredient_id' => $this->mexican],
            ['ingredient_group_id' => 2, 'ingredient_id' => $this->rice],
        ]);
    }

    private function rows(): array
    {
        return DB::table('client_ingredient_exclusion')->where('client_id', $this->client)->orderBy('ingredient_id')
            ->get(['ingredient_id', 'source', 'note'])->map(fn ($r) => [(int) $r->ingredient_id, $r->source, $r->note])->all();
    }

    public function test_saving_a_profile_expands_its_groups_into_ingredient_exclusions_and_keeps_manual_rows(): void
    {
        DB::table('client_ingredient_exclusion')->insert(['client_id' => $this->client, 'ingredient_id' => $this->corn, 'note' => 'сказав по телефону']);

        ClientDietProfile::create(['client_id' => $this->client, 'mode' => 'cycle_replacements', 'excluded_groups' => ['кукурудза']]);

        $this->assertSame([
            [$this->corn, 'manual', 'сказав по телефону'],          // ручний рядок лишився ручним
            [$this->mexican, 'profile_group', 'група «Кукурудза»'],
        ], $this->rows());
    }

    public function test_removing_a_group_from_the_profile_removes_only_the_rows_it_added(): void
    {
        $profile = ClientDietProfile::create(['client_id' => $this->client, 'mode' => 'cycle_replacements', 'excluded_groups' => ['кукурудза', 'крупи']]);
        $this->assertCount(3, $this->rows());

        $profile->update(['excluded_groups' => ['крупи']]);

        $this->assertSame([[$this->rice, 'profile_group', 'група «Крупи (каші)»']], $this->rows());
    }

    public function test_sync_reports_what_it_changed_and_an_unknown_group_adds_nothing(): void
    {
        $sync = app(ProfileExclusionSync::class);

        $this->assertSame(['added' => 2, 'removed' => 0, 'total' => 2], $sync->sync($this->client, ['кукурудза', 'немає_такої']));
        $this->assertSame(['added' => 1, 'removed' => 2, 'total' => 1], $sync->sync($this->client, ['крупи']));
        $this->assertSame(['added' => 0, 'removed' => 1, 'total' => 0], $sync->sync($this->client, []));
    }
}
