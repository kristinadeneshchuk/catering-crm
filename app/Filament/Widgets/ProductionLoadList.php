<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use App\Models\OrderDay;
use Carbon\Carbon;

class ProductionLoadList extends Widget
{
    protected static string $view = 'filament.widgets.production-load-list';
    
    // Ставимо сортування 3, щоб він точно став під твоїми основними картками
    protected static ?int $sort = 3; 

    protected int | string | array $columnSpan = 'full';

    private const PROJECTS = ['avocado_food' => 'Avocado', 'u_fit' => 'U-Fit', 'afood' => 'A-Food', 'other' => 'Інше'];

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager'], true);
    }

    protected function getViewData(): array
    {
        $startDate = Carbon::now()->startOfDay();
        $endDate = Carbon::now()->addDays(19)->endOfDay();

        $rows = OrderDay::query()
            ->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->whereBetween('order_days.date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->whereIn('orders.status', ['new', 'active'])
            ->selectRaw('order_days.date as d, orders.project as p, orders.menu_type as m, count(order_days.id) as c')
            ->groupBy('d', 'p', 'm')
            ->get();

        // Розбивка дня: проєкт → [усього, з них індивідуальних] — як у щоденному звіті.
        $daysData = [];
        foreach ($rows as $r) {
            $key = Carbon::parse($r->d)->format('Y-m-d');
            $p = $r->p ?: 'other';
            $daysData[$key]['total'] = ($daysData[$key]['total'] ?? 0) + $r->c;
            $daysData[$key]['projects'][$p]['all'] = ($daysData[$key]['projects'][$p]['all'] ?? 0) + $r->c;
            if ($r->m === 'individual') {
                $daysData[$key]['individual'] = ($daysData[$key]['individual'] ?? 0) + $r->c;
                $daysData[$key]['projects'][$p]['ind'] = ($daysData[$key]['projects'][$p]['ind'] ?? 0) + $r->c;
            }
        }

        $list = [];
        $daysOfWeek = [
            0 => 'Неділя', 1 => 'Понеділок', 2 => 'Вівторок', 3 => 'Середа',
            4 => 'Четвер', 5 => 'П\'ятниця', 6 => 'Субота'
        ];

        for ($i = 0; $i < 20; $i++) {
            $currentDate = Carbon::now()->addDays($i);
            $dateString = $currentDate->format('Y-m-d');
            
            $list[] = [
                'date' => $currentDate->format('d.m.Y'),
                'day_name' => $daysOfWeek[$currentDate->dayOfWeek],
                'total' => $daysData[$dateString]['total'] ?? 0,
                'individual' => $daysData[$dateString]['individual'] ?? 0,
                'projects' => collect(self::PROJECTS)
                    ->map(fn ($label, $code) => [
                        'label' => $label,
                        'all' => $daysData[$dateString]['projects'][$code]['all'] ?? 0,
                        'ind' => $daysData[$dateString]['projects'][$code]['ind'] ?? 0,
                    ])
                    ->filter(fn ($p) => $p['all'] > 0)
                    ->values()
                    ->all(),
            ];
        }

        return [
            'loadList' => $list,
        ];
    }
}