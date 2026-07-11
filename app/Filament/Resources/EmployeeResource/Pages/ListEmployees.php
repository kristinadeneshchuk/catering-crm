<?php

namespace App\Filament\Resources\EmployeeResource\Pages;

use App\Filament\Resources\EmployeeResource;
use App\Models\Employee;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListEmployees extends ListRecords
{
    protected static string $resource = EmployeeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Швидкі таби зверху за посадою — щоб не лізти щоразу в фільтр.
     * Бейджі показують кількість не-архівованих на кожній посаді.
     */
    public function getTabs(): array
    {
        $baseQuery = fn () => Employee::query()->whereNull('archived_at');

        // Все, що не курʼєр / не кухар / не менеджер — сюди (дизайнер, таргетолог,
        // бухгалтер, прибиральниця, пакувальник, SMM, адмін тощо).
        $otherPositions = ['courier', 'cook', 'manager'];

        return [
            'all' => Tab::make('Всі')
                ->badge($baseQuery()->count()),

            'courier' => Tab::make("Кур'єри")
                ->modifyQueryUsing(fn (Builder $query) => $query->where('position', 'courier'))
                ->badge($baseQuery()->where('position', 'courier')->count()),

            'cook' => Tab::make('Кухарі')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('position', 'cook'))
                ->badge($baseQuery()->where('position', 'cook')->count()),

            'manager' => Tab::make('Менеджери')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('position', 'manager'))
                ->badge($baseQuery()->where('position', 'manager')->count()),

            'other' => Tab::make('Інші')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotIn('position', $otherPositions))
                ->badge($baseQuery()->whereNotIn('position', $otherPositions)->count()),
        ];
    }
}
