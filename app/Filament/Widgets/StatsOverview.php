<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class StatsOverview extends BaseWidget
{
    // Обновление раз в 15 секунд
    protected static ?string $pollingInterval = '15s';

    public static function canView(): bool
{
    // Віджет бачать лише адмін та менеджер
    return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
}

    protected function getStats(): array
    {
        // 1. Активные (с правильной проверкой дат)
        $activeClients = Order::where('status', 'active')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->count();

        // 2. Выручка
        $revenue = Order::whereMonth('created_at', Carbon::now()->month)
            ->sum('total_price');

        // 3. Заканчиваются скоро
        $expiringSoon = Order::where('status', 'active')
            ->whereDate('end_date', '>=', now())
            ->whereDate('end_date', '<=', now()->addDays(3))
            ->count();

        return [
            Stat::make('Активні Клієнти', $activeClients)
                ->description('Людей їдять сьогодні')
                ->descriptionIcon('heroicon-m-users')
                ->color('success')
                ->chart([7, 3, 4, 5, 6, 3, 5, 8]),

            Stat::make('Виручка (Цей місяць)', number_format($revenue, 0, '.', ' ') . ' ₴')
                ->description('Сума продажів')
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('primary')
                ->chart([1500, 2000, 1800, 3500, 4200]), 

            Stat::make('Закінчуються скоро', $expiringSoon)
                ->description('Клієнтів на продовження (3 дні)')
                ->descriptionIcon('heroicon-m-bell-alert')
                ->color($expiringSoon > 0 ? 'warning' : 'gray'),
        ];
    }
}