<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

class NewClientsStats extends Widget
{
    protected static string $view = 'filament.widgets.new-clients-stats';
    protected static ?int $sort = 5;
    protected static ?string $pollingInterval = '60s';
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager']);
    }

    protected function getViewData(): array
    {
        $today      = now()->startOfDay();
        $weekStart  = $today->copy()->subDays(6);   // сьогодні + 6 попередніх днів = 7-денне вікно
        $monthStart = $today->copy()->subDays(29);  // сьогодні + 29 = 30-денне вікно

        $countToday = Client::whereDate('created_at', $today)->count();
        $countWeek  = Client::where('created_at', '>=', $weekStart)->count();
        $countMonth = Client::where('created_at', '>=', $monthStart)->count();

        // Розподіл по джерелах за 30 днів. Пусті — в одну купу "Без джерела".
        $bySource = Client::select(
                DB::raw("COALESCE(NULLIF(TRIM(sales_source), ''), 'Без джерела') as source"),
                DB::raw('COUNT(*) as cnt'),
            )
            ->where('created_at', '>=', $monthStart)
            ->groupBy('source')
            ->orderByDesc('cnt')
            ->get()
            ->map(fn ($r) => ['source' => $r->source, 'count' => (int) $r->cnt])
            ->all();

        return [
            'countToday'   => $countToday,
            'countWeek'    => $countWeek,
            'countMonth'   => $countMonth,
            'weekStartFmt' => $weekStart->format('d.m'),
            'monthStartFmt'=> $monthStart->format('d.m'),
            'todayFmt'     => $today->format('d.m'),
            'bySource'     => $bySource,
        ];
    }
}
