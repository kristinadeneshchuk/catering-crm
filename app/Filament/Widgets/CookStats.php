<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use App\Models\Order;
use App\Models\DailyMenu;
use App\Models\Setting; // Обов'язково для доступу до тривалості циклу
use Carbon\Carbon;

class CookStats extends BaseWidget
{
    /**
     * ПРАВИЛО ДОСТУПУ: Бачить тільки Кухар та Адмін
     */
    public static function canView(): bool
    {
        return false;
    }

    protected function getStats(): array
    {
        $date = now()->format('Y-m-d');

        // 1. Параметри глобального циклу
        $cycleDays = (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01'); // Наш стабільний "якір"

        // 2. Математичний розрахунок дня циклу для Avocado Food
        $diffInDays = abs(now()->diffInDays($anchorDate));
        $globalDay = ($diffInDays % $cycleDays) + 1;

        // 3. Кількість активних порцій на сьогодні (календарна дата)
        $totalOrders = Order::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', ['new', 'active'])
            ->count();

        // 4. Шукаємо меню за day_number замість дати
        $menu = DailyMenu::where('day_number', $globalDay)
            ->withCount('menuItems')
            ->first();
            
        $dishesCount = $menu ? $menu->menu_items_count : 0;

        return [
            Stat::make('Порцій на сьогодні', $totalOrders)
                ->description('Активні пакети для клієнтів')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('success'),

            Stat::make('Різних страв у меню', $dishesCount)
                ->description("Сьогодні День №{$globalDay} циклу") // Підказка для кухаря
                ->descriptionIcon('heroicon-m-cake')
                ->color('warning'),

            Stat::make('Порцій завтра', Order::where('start_date', '<=', now()->addDay()->format('Y-m-d'))
                    ->where('end_date', '>=', now()->addDay()->format('Y-m-d'))
                    ->whereIn('status', ['new', 'active'])
                    ->count())
                ->description('Планування на завтра')
                ->descriptionIcon('heroicon-m-arrow-right-circle')
                ->color('info'),
        ];
    }
}