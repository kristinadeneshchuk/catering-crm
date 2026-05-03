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

        // 1. Дефолтний план меню (TODO multi-plan: для віджета показуємо лише дефолт)
        $defaultPlan = \App\Models\MenuPlan::default();
        $globalDay = $defaultPlan ? $defaultPlan->globalDayFor(now()) : 0;

        // 2. Кількість активних порцій на сьогодні (календарна дата)
        $totalOrders = Order::where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->whereIn('status', ['new', 'active'])
            ->count();

        // 3. Шукаємо меню дефолтного плану за day_number
        $menu = $defaultPlan
            ? DailyMenu::where('menu_plan_id', $defaultPlan->id)
                ->where('day_number', $globalDay)
                ->withCount('menuItems')
                ->first()
            : null;
            
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