<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Тариф на електроенергію (грн за кВт·год) з датою початку дії. */
class EnergyTariff extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'price' => 'float'];

    /** Тариф, що діяв на дату; якщо раніший за перший запис — беремо перший. */
    public static function priceOn($date): float
    {
        $date = \Carbon\Carbon::parse($date)->toDateString();
        $row = static::whereDate('starts_on', '<=', $date)->orderByDesc('starts_on')->first()
            ?? static::orderBy('starts_on')->first();

        return (float) ($row->price ?? 0);
    }
}
