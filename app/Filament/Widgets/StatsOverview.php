<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\Setting;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class StatsOverview extends BaseWidget
{
    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    protected function getStats(): array
    {
        // Отримуємо поточну дату без часу
        $now = now()->startOfDay();
        $todayStr = $now->format('Y-m-d');

        // === 1. РОЗРАХУНОК ДНЯ ЦИКЛУ (ДЕНЬ У ДЕНЬ) ===
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $startDateStr = Setting::where('key', 'menu_cycle_start_date')->value('value') ?: $todayStr;
        
        // Встановлюємо початок дня для дати відліку, щоб diffInDays рахував коректно
        $anchorDate = Carbon::parse($startDateStr)->startOfDay();
        
        // Рахуємо різницю в днях (16.02 - 16.02 = 0)
        $diff = abs($now->diffInDays($anchorDate));
        
        // Формула: (0 % 10) + 1 = 1
        $currentDayNumber = ($diff % $cycleDays) + 1;

        // === 2. АКТИВНІ КЛІЄНТИ ===
        $activeTodayCount = Order::whereIn('status', ['active', 'new'])
            ->whereHas('orderDays', function ($query) use ($todayStr) {
                $query->where('date', $todayStr);
            })
            ->count();

        // 3. Виручка за місяць
        $revenue = Order::whereMonth('created_at', Carbon::now()->month)
            ->sum('total_price');

        // 4. Закінчуються скоро
        $expiringSoon = Order::where('status', 'active')
            ->whereDate('end_date', '>=', $todayStr)
            ->whereDate('end_date', '<=', $now->copy()->addDays(3)->format('Y-m-d'))
            ->count();

        return [
            Stat::make('День Меню', "№ {$currentDayNumber}")
                ->description("Сьогоднішній день раціону")
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color('info'),

            Stat::make('Активні Клієнти', $activeTodayCount)
                ->description('Отримують їжу сьогодні')
                ->descriptionIcon('heroicon-m-users')
                ->color('success')
                ->chart([7, 3, 4, 5, 6, 3, 5, 8]),

            Stat::make('Виручка (Цей місяць)', number_format($revenue, 0, '.', ' ') . ' ₴')
                ->description('Сума всіх замовлень')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('primary'), 

            Stat::make('Закінчуються скоро', $expiringSoon)
                ->description('Продовження за 3 дні')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($expiringSoon > 0 ? 'warning' : 'gray'),
        ];
    }
}