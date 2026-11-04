<?php

namespace App\Services\Agents;

use App\Models\IngredientGroup;
use Illuminate\Support\Facades\DB;

/**
 * Групи «не можна» з профілю харчування → рядки client_ingredient_exclusion з source=profile_group.
 *
 * Старі споживачі (фасування, персональні меню, звіт виробництва) читають лише
 * виключення продуктів і про групи профілю не знають — тому групу розгортаємо
 * в продукти при кожному збереженні профілю. Ручні рядки (source=manual/inbox)
 * не чіпаємо; свої рядки, яких у групах більше нема, прибираємо.
 */
class ProfileExclusionSync
{
    public const SOURCE = 'profile_group';

    /** @return array{added:int, removed:int, total:int} */
    public function sync(int $clientId, array $groupSlugs): array
    {
        $names = collect(IngredientGroupCatalog::all())->map(fn ($g) => $g['name']);
        $map = IngredientGroup::map();

        $target = [];                                   // ingredient_id → note
        foreach ($groupSlugs as $slug) {
            foreach ($map[$slug] ?? [] as $id) {
                $target[$id] ??= 'група «' . ($names[$slug] ?? $slug) . '»';
            }
        }

        return DB::transaction(function () use ($clientId, $target) {
            $rows = DB::table('client_ingredient_exclusion')->where('client_id', $clientId)->get(['ingredient_id', 'source']);
            $have = $rows->pluck('ingredient_id')->map(fn ($v) => (int) $v)->all();
            $ours = $rows->where('source', self::SOURCE)->pluck('ingredient_id')->map(fn ($v) => (int) $v)->all();

            $stale = array_values(array_diff($ours, array_keys($target)));
            if ($stale) {
                DB::table('client_ingredient_exclusion')->where('client_id', $clientId)->where('source', self::SOURCE)
                    ->whereIn('ingredient_id', $stale)->delete();
            }

            $now = now();
            $insert = collect($target)->except($have)->map(fn ($note, $id) => [
                'client_id' => $clientId, 'ingredient_id' => $id, 'source' => self::SOURCE, 'note' => $note,
                'created_at' => $now, 'updated_at' => $now,
            ])->values()->all();
            if ($insert) {
                DB::table('client_ingredient_exclusion')->insert($insert);
            }

            return [
                'added'   => count($insert),
                'removed' => count($stale),
                'total'   => count($have) - count($stale) + count($insert),
            ];
        });
    }
}
