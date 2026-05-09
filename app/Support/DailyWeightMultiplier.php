<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\Carbon;

class DailyWeightMultiplier
{
    private static array $cache = [];

    public static function for(string|Carbon|null $date): float
    {
        if ($date === null) return 1.0;

        $key = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $value = Setting::where('key', 'daily_weight_multiplier.' . $key)->value('value');
        $multiplier = is_numeric($value) ? (float) $value : 1.0;

        if ($multiplier <= 0 || $multiplier > 2) $multiplier = 1.0;

        return self::$cache[$key] = $multiplier;
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
