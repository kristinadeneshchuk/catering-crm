<?php

namespace App\Services\Couriers;

use App\Models\CourierMileageLog;
use App\Models\CourierShiftReport;

/**
 * Позначки у звіті зміни курʼєра — те, на що адміну варто подивитись перед
 * підтвердженням. Правила детерміновані: ШІ пізніше лише допише пояснення
 * (фото одометра, маршрут), але сам факт «забагато км» рахує код.
 *
 * Кожна позначка: code, severity (red / yellow / info), text — одне речення
 * з цифрами, з чим звірити.
 */
class CourierReportAnomalies
{
    /**
     * @return array<int, array{code: string, severity: string, text: string}>
     */
    public function detect(CourierShiftReport $report, array $parsed): array
    {
        $cfg      = config('ops.anomaly');
        $expected = $report->expected ?? [];
        $unit     = $report->employee?->mileage_unit === 'mi' ? 'mi' : 'km';
        $start    = $parsed['start_km'] ?? ($expected['start_km'] ?? null);
        $end      = $parsed['end_km'] ?? null;
        $out      = [];

        if ($start !== null && $end !== null && $end > $start) {
            $raw   = (int) $end - (int) $start;
            $km    = $unit === 'mi' ? (int) round($raw * CourierMileageLog::MI_TO_KM) : $raw;
            $plan  = (float) ($expected['distance'] ?? 0);
            $stops = (int) ($expected['stops'] ?? 0);
            $route = ! empty($expected['route_nums']) ? 'Маршрут №'.implode(', №', $expected['route_nums']).', ' : '';

            if ($unit === 'mi') {
                $out[] = $this->mark('miles', 'info', "Одометр у милях: {$raw} mi = {$km} км (× ".CourierMileageLog::MI_TO_KM.').');
            }

            if ($plan > 0) {
                $ratio = $km / $plan;

                if ($ratio > $cfg['km_over_plan_ratio']) {
                    $out[] = $this->mark(
                        'km_over_plan',
                        $ratio > $cfg['km_over_plan_red'] ? 'red' : 'yellow',
                        "{$km} км при плані ".(int) round($plan).' (×'.number_format($ratio, 1, ',', '').'). '
                            .$route."{$stops} точок. Звірити трек у Мурашці.",
                    );
                }
            } elseif ($stops > 0 && ($perStop = $this->historyKmPerStop($report)) > 0) {
                $usual = $perStop * $stops;

                if ($km > $usual * $cfg['km_over_history']) {
                    $out[] = $this->mark(
                        'km_over_history',
                        'yellow',
                        "{$km} км на {$stops} точок, зазвичай у цього курʼєра ~".(int) round($usual)
                            .' км. Плану з Мурашки немає — звірити трек.',
                    );
                }
            }
        }

        // Старт має збігатися з фінішем попередньої зміни.
        $prevEnd = $expected['start_km'] ?? null;
        $sent    = $parsed['start_km'] ?? null;

        if ($prevEnd !== null && $sent !== null && (int) $sent !== (int) $prevEnd) {
            $gap = (int) $sent - (int) $prevEnd;

            if ($gap < 0) {
                $out[] = $this->mark('start_below_prev', 'red', "Старт {$sent} менший за фініш попередньої зміни {$prevEnd} — помилка в цифрах або інша машина.");
            } elseif ($gap > (int) $cfg['start_gap_km']) {
                $out[] = $this->mark('start_gap', 'yellow', "Між змінами +{$gap} {$unit}: попередній фініш {$prevEnd}, зараз старт {$sent}.");
            }
        }

        // Ціна пального проти звичної.
        $price = $parsed['fuel_price'] ?? null;

        if ($price !== null && ($median = $this->recentFuelMedian()) > 0) {
            $dev = abs((float) $price - $median) / $median;

            if ($dev > (float) $cfg['fuel_price_deviation']) {
                $out[] = $this->mark('fuel_price_off', 'yellow', 'Пальне '.number_format((float) $price, 2, ',', '')
                    .' грн/л, звично ~'.number_format($median, 2, ',', '').'.');
            }
        }

        // Готівка: отримав не те, що чекали.
        foreach ($parsed['cash'] ?? [] as $line) {
            $exp = (float) ($line['expected'] ?? 0);
            $got = (float) ($line['received'] ?? 0);

            if ($exp > 0 && abs($exp - $got) >= 1) {
                $who   = trim(($line['client'] ?? '').' #'.($line['order_id'] ?? ''));
                $out[] = $this->mark('cash_diff', 'yellow', "{$who}: чекали ".$this->money($exp).', отримав '.$this->money($got).'.');
            }
        }

        return $out;
    }

    /** Короткий текст для адміна з усіх позначок. */
    public function summary(array $anomalies): ?string
    {
        $texts = collect($anomalies)->where('severity', '!=', 'info')->pluck('text');

        return $texts->isEmpty() ? null : $texts->implode(' ');
    }

    /**
     * Медіана км на точку за останні 20 підтверджених змін курʼєра.
     */
    protected function historyKmPerStop(CourierShiftReport $report): float
    {
        $perStop = CourierShiftReport::where('employee_id', $report->employee_id)
            ->where('status', CourierShiftReport::STATUS_ACCEPTED)
            ->where('id', '!=', $report->id)
            ->orderByDesc('date')
            ->limit(20)
            ->get()
            ->map(function (CourierShiftReport $r) {
                $s = $r->mileageSummary();

                return ($s['km'] && $s['stops'] > 0) ? $s['km'] / $s['stops'] : null;
            })
            ->filter()
            ->sort()
            ->values();

        return $perStop->count() >= 3 ? (float) $perStop->median() : 0.0;
    }

    protected function recentFuelMedian(): float
    {
        $prices = CourierMileageLog::where('fuel_price_per_liter', '>', 0)
            ->whereDate('date', '>=', now()->subDays(7)->toDateString())
            ->pluck('fuel_price_per_liter')
            ->map(fn ($p) => (float) $p);

        return $prices->count() >= 3 ? (float) $prices->median() : 0.0;
    }

    private function mark(string $code, string $severity, string $text): array
    {
        return ['code' => $code, 'severity' => $severity, 'text' => $text];
    }

    private function money(float $v): string
    {
        return number_format($v, 0, ',', ' ').' ₴';
    }
}
