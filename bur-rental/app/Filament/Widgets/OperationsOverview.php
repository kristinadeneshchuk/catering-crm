<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Models\Lead;
use App\Models\Product;
use App\Models\UnavailableDate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Те, на що менеджер дивиться першим ділом зранку: що видавати, що приймати,
 * хто прострочив і скільки заявок висить необробленими.
 */
class OperationsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $issueToday = Booking::whereDate('date_from', today())
            ->whereIn('status', ['new', 'confirmed'])->count();

        $returnToday = Booking::whereDate('date_to', today())
            ->where('status', 'issued')->count();

        $overdue = Booking::whereDate('date_to', '<', today())
            ->where('status', 'issued')->count();

        $newBookings = Booking::where('status', 'new')->count();
        $newLeads = Lead::where('status', 'new')->count();

        // Завантаження парку: скільки одиниць зайнято саме сьогодні.
        $busyToday = UnavailableDate::whereDate('date', today())->count();
        $fleet = max(1, Product::count());

        return [
            Stat::make('Видати сьогодні', $issueToday)
                ->description($issueToday ? 'броні чекають на видачу' : 'нічого не заплановано')
                ->color($issueToday ? 'primary' : 'gray'),

            Stat::make('Прийняти сьогодні', $returnToday)
                ->description('повернення за планом')
                ->color($returnToday ? 'info' : 'gray'),

            Stat::make('Прострочені', $overdue)
                ->description($overdue ? 'техніка не повернулася вчасно' : 'усе повернули вчасно')
                ->color($overdue ? 'danger' : 'success'),

            Stat::make('Нові броні', $newBookings)
                ->description('чекають на підтвердження')
                ->color($newBookings ? 'warning' : 'gray'),

            Stat::make('Нові заявки', $newLeads)
                ->description('дзвінки і запити КП')
                ->color($newLeads ? 'warning' : 'gray'),

            Stat::make('Зайнято сьогодні', $busyToday)
                ->description(round($busyToday / $fleet * 100).'% позицій каталогу')
                ->color('gray'),
        ];
    }
}
