<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Виручка по днях за останні два тижні. Застава сюди не входить —
 * це не дохід, а заморожені гроші клієнта.
 */
class BookingsChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Виручка за два тижні, ₴';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn (int $back) => Carbon::today()->subDays($back));

        $totals = Booking::whereDate('created_at', '>=', Carbon::today()->subDays(13))
            ->whereNot('status', 'cancelled')
            ->get()
            ->groupBy(fn (Booking $b) => $b->created_at->toDateString())
            ->map(fn ($group) => $group->sum(fn (Booking $b) => $b->rent_total + $b->extras_total + $b->delivery_total));

        return [
            'datasets' => [[
                'label' => 'Оренда + витратники + доставка',
                'data' => $days->map(fn (Carbon $d) => $totals[$d->toDateString()] ?? 0)->all(),
                'borderColor' => '#0E5B46',
                'backgroundColor' => 'rgba(14, 91, 70, 0.12)',
                'fill' => true,
            ]],
            'labels' => $days->map(fn (Carbon $d) => $d->format('d.m'))->all(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
