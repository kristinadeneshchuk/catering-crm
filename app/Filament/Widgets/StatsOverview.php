<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\OrderDay;
use App\Models\Setting;
use Filament\Widgets\Widget;
use Carbon\Carbon;

class StatsOverview extends Widget
{
    protected static string $view = 'filament.widgets.stats-overview';
    protected static ?int $sort = 2;
    protected static ?string $pollingInterval = '30s';
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['admin', 'manager']);
    }

    protected function getViewData(): array
    {
        $now      = now()->startOfDay();
        $today    = $now->format('Y-m-d');
        $tomorrow = $now->copy()->addDay()->format('Y-m-d');

        // День меню (TODO multi-plan: для огляду показуємо день дефолтного плану)
        $defaultPlan = \App\Models\MenuPlan::default();
        $menuDay     = $defaultPlan ? $defaultPlan->globalDayFor($now) : 0;

        // Порцій сьогодні
        $todayCount = OrderDay::where('date', $today)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['active', 'new']))
            ->count();

        // Порцій завтра
        $tomorrowCount = OrderDay::where('date', $tomorrow)
            ->whereHas('order', fn($q) => $q->whereIn('status', ['active', 'new']))
            ->count();

        // Всього активних клієнтів
        $totalActive = Order::whereIn('status', ['active', 'new'])
            ->whereDate('end_date', '>=', $today)
            ->count();

        // Закінчуються за 3 дні
        $expiringSoon = Order::where('status', 'active')
            ->whereDate('end_date', '>=', $today)
            ->whereDate('end_date', '<=', $now->copy()->addDays(3)->format('Y-m-d'))
            ->count();

        // Несплачені
        $unpaidOrders = Order::where('is_paid', false)
            ->whereIn('status', ['active', 'new'])
            ->where(fn($q) => $q->where('final_price', '>', 0)->orWhere('total_price', '>', 0));

        $unpaidCount = $unpaidOrders->count();
        $unpaidSum   = $unpaidOrders->sum(\DB::raw('COALESCE(NULLIF(final_price,0), total_price)'));

        return compact(
            'menuDay', 'cycleDays',
            'todayCount', 'tomorrowCount',
            'totalActive', 'expiringSoon',
            'unpaidCount', 'unpaidSum'
        );
    }
}
