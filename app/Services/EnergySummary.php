<?php

namespace App\Services;

use App\Models\EnergyTariff;
use App\Models\GeneratorRefuel;
use App\Models\MeterReading;
use App\Models\OrderDay;
use Carbon\Carbon;

/**
 * Зведення енергії за календарний місяць.
 *
 * Показники знімають не першого числа, тому спожите між двома показниками
 * розкладається по днях рівномірно, і в місяць потрапляє лише його частка.
 * Місяць «закритий», якщо показники покривають його повністю.
 */
class EnergySummary
{
    /** @return array<string, mixed> */
    public static function month(string $ym): array
    {
        $start = Carbon::parse($ym.'-01')->startOfDay();
        $end   = $start->copy()->addMonth();          // не включно

        $kwh = 0.0;
        $cost = 0.0;
        $covered = 0;
        $readings = MeterReading::orderBy('taken_on')->get();

        for ($i = 1; $i < $readings->count(); $i++) {
            $a = $readings[$i - 1];
            $b = $readings[$i];
            $days = (int) $a->taken_on->diffInDays($b->taken_on);
            if ($days <= 0) {
                continue;
            }
            $from = $a->taken_on->greaterThan($start) ? $a->taken_on : $start;
            $to   = $b->taken_on->lessThan($end) ? $b->taken_on : $end;
            $in   = (int) $from->diffInDays($to, false);
            if ($in <= 0) {
                continue;
            }
            $part = ($b->reading - $a->reading) * $in / $days;
            $kwh += $part;
            $cost += $part * EnergyTariff::priceOn($b->taken_on);
            $covered += $in;
        }

        $refuels = GeneratorRefuel::whereDate('date', '>=', $start)->whereDate('date', '<', $end)->get();
        $rations = OrderDay::whereDate('date', '>=', $start)->whereDate('date', '<', $end)->count();
        $fuel    = (float) $refuels->sum('amount');
        $total   = $cost + $fuel;

        return [
            'month'      => $start->format('Y-m'),
            'grid_kwh'   => (int) round($kwh),
            'grid_cost'  => round($cost),
            'complete'   => $covered >= $start->daysInMonth,
            'days'       => $covered,
            'fuel_l'     => round((float) $refuels->sum('liters'), 1),
            'fuel_cost'  => round($fuel),
            'total'      => round($total),
            'rations'    => $rations,
            'per_ration' => $rations > 0 ? round($total / $rations, 1) : null,
        ];
    }

    /** Останні $n місяців, новіші зверху. */
    public static function lastMonths(int $n = 6): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::month(Carbon::today()->startOfMonth()->subMonths($i)->format('Y-m'));
        }

        return $out;
    }
}
