<?php

namespace App\Support;

use App\Models\Order;

/**
 * Коротко, чого клієнт не їсть: алергії, набори («БЕЗЛАКТОЗНІ») однією назвою,
 * решта виключених продуктів (до $limit назв), виключені страви. Для кухні —
 * щоб, замінюючи, випадково не поклала те, що йому не можна.
 */
class NoEat
{
    public static function summary(Order $o, int $limit = 15): string
    {
        $bundles = $o->client?->replacementBundles ?? collect();
        $inBundles = $bundles->flatMap(fn ($b) => $b->items->pluck('original_ingredient_id'))->flip();
        $manual = ($o->client?->ingredientExclusions ?? collect())->merge($o->ingredientExclusions ?? collect())
            ->reject(fn ($i) => isset($inBundles[$i->id]))
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->unique()->values();
        $ex = $manual->take($limit)->implode(', ').($manual->count() > $limit ? ' … ще '.($manual->count() - $limit) : '');
        // Страви — коротко: без напівфабрикатів, до 6 назв (кухня замінює продукти, а не страви).
        $dishes = ($o->client?->dishExclusions ?? collect())->pluck('name')
            ->reject(fn ($n) => preg_match('~н[\\\\/]ф\s*$~u', trim($n)))->map(fn ($n) => trim($n))->unique()->values();
        $dx = $dishes->take(6)->implode(', ').($dishes->count() > 6 ? ' … ще '.($dishes->count() - 6).' страв' : '');

        return implode('. ', array_filter([
            trim((string) $o->client?->allergies),
            $bundles->pluck('name')->map(fn ($n) => mb_strtoupper($n))->implode(', '),
            $ex ? 'Без: '.$ex : '',
            $dx ? 'Не давати: '.$dx : '',
        ]));
    }
}
