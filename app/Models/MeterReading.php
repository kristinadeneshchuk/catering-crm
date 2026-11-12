<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * Показник лічильника електроенергії. Спожите рахується від попереднього
 * запису за датою зняття, сума — за тарифом, що діяв на дату зняття.
 */
class MeterReading extends Model
{
    protected $guarded = [];

    protected $casts = ['taken_on' => 'date', 'reading' => 'integer', 'date_confirmed' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::saving(function (MeterReading $r) {
            $date = $r->taken_on->toDateString();
            $prev = static::whereDate('taken_on', '<', $date)->whereKeyNot($r->id)->orderByDesc('taken_on')->first();
            $next = static::whereDate('taken_on', '>', $date)->whereKeyNot($r->id)->orderBy('taken_on')->first();

            if ($prev && $r->reading < $prev->reading) {
                throw ValidationException::withMessages(['reading' => "Показник менший за попередній ({$prev->reading} на {$prev->taken_on->format('d.m.Y')})."]);
            }
            if ($next && $r->reading > $next->reading) {
                throw ValidationException::withMessages(['reading' => "Показник більший за наступний ({$next->reading} на {$next->taken_on->format('d.m.Y')})."]);
            }
        });
    }

    public function previous(): ?self
    {
        return static::whereDate('taken_on', '<', $this->taken_on->toDateString())->orderByDesc('taken_on')->first();
    }

    /** @return array{kwh:int,days:int,per_day:float,price:float,cost:float}|null */
    public function period(): ?array
    {
        $prev = $this->previous();
        if (! $prev) {
            return null;
        }
        $kwh   = $this->reading - $prev->reading;
        $days  = max(1, (int) $prev->taken_on->diffInDays($this->taken_on));
        $price = EnergyTariff::priceOn($this->taken_on);

        return [
            'from'    => $prev->taken_on,
            'kwh'     => $kwh,
            'days'    => $days,
            'per_day' => round($kwh / $days, 1),
            'price'   => $price,
            'cost'    => round($kwh * $price, 2),
        ];
    }
}
