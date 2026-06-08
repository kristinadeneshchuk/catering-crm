<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateHistory extends Model
{
    protected $table = 'rate_history';

    protected $fillable = ['scope', 'value', 'effective_from'];

    protected $casts = [
        'value'          => 'decimal:2',
        'effective_from' => 'date',
    ];

    /**
     * Записати нове значення ставки (діє з сьогодні; один запис на день на scope).
     */
    public static function record(string $scope, float $value, ?string $date = null): void
    {
        $date = $date ?: now()->toDateString();
        static::where('scope', $scope)->whereDate('effective_from', $date)->delete();
        static::create([
            'scope'          => $scope,
            'value'          => $value,
            'effective_from' => $date,
        ]);
    }

    /**
     * Завантажити історію для набору scope-ів, відсортовану за датою (для PHP-резолву).
     * Повертає [scope => [ ['d' => 'Y-m-d', 'v' => float], ... ] ] за зростанням дати.
     */
    public static function seriesFor(array $scopes): array
    {
        $rows = static::whereIn('scope', $scopes)
            ->orderBy('effective_from')
            ->get(['scope', 'value', 'effective_from']);

        $series = [];
        foreach ($rows as $r) {
            $series[$r->scope][] = ['d' => $r->effective_from->format('Y-m-d'), 'v' => (float) $r->value];
        }
        return $series;
    }

    /**
     * Значення scope на конкретну дату: останній запис із effective_from <= $date.
     * $oneSeries — масив ['d'=>..,'v'=>..] за зростанням дати.
     */
    public static function valueOn(?array $oneSeries, string $date): float
    {
        if (empty($oneSeries)) return 0.0;
        $val = 0.0;
        foreach ($oneSeries as $point) {
            if ($point['d'] <= $date) {
                $val = $point['v'];
            } else {
                break;
            }
        }
        return $val;
    }
}
