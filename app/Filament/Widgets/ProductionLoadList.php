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

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager'], true);
    }

    protected function getViewData(): array
    {
        $startDate = Carbon::now()->startOfDay();
        $endDate = Carbon::now()->addDays(19)->endOfDay();

        $daysData = OrderDay::whereBetween('date', [$startDate->format('Y-m-d'), $endDate->format('Y-m-d')])
            ->whereHas('order', function ($query) {
                $query->whereIn('status', ['new', 'active']);
            })
            ->selectRaw('date, count(id) as total')
            ->groupBy('date')
            ->pluck('total', 'date');

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
                'total' => $daysData->get($dateString, 0)
            ];
        }

        return [
            'loadList' => $list,
        ];
    }
}