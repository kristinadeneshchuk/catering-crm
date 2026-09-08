<?php

namespace App\Support;

/**
 * Фіксовані групи калоражу для фасувального листа.
 *
 * Кухня фасує не під кожен калораж окремо, а під кілька усереднених груп:
 * клієнти на 900 і на 1300 отримують однаковий грамаж. Група — це колонка
 * таблиці; грамаж у ній рахується як середнє по замовленнях групи
 * (sum_scale / count у PackagingList та PrintController).
 */
class CalorieGroup
{
    /** @var array<int, array{0:int,1:int}> межі груп, включно */
    private const GROUPS = [
        [900, 1300],
        [1400, 1700],
        [1800, 1900],
        [2000, 2500],
        [3000, 3000],
    ];

    /**
     * Ключ колонки для калоражу — нижня межа групи.
     * Калораж поза межами (800, 2600, 3200) прилипає до найближчої групи.
     */
    public static function keyFor(int $calories): int
    {
        $bestKey = self::GROUPS[0][0];
        $bestDistance = PHP_INT_MAX;

        foreach (self::GROUPS as [$from, $to]) {
            $distance = $calories < $from
                ? $from - $calories
                : ($calories > $to ? $calories - $to : 0);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestKey = $from;
            }

            if ($distance === 0) {
                break;
            }
        }

        return $bestKey;
    }

    /** Підпис колонки: «900–1300» або «3000», якщо група з одного значення. */
    public static function labelFor(int $key): string
    {
        foreach (self::GROUPS as [$from, $to]) {
            if ($from === $key) {
                return $from === $to ? (string) $from : $from.'–'.$to;
            }
        }

        return (string) $key;
    }
}
