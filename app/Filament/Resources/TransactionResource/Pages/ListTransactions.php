<?php

namespace App\Filament\Resources\TransactionResource\Pages;

use App\Filament\Resources\TransactionResource;
use App\Services\DailyCashService;
use Carbon\Carbon;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListTransactions extends ListRecords
{
    protected static string $resource = TransactionResource::class;

    // Використовуємо власний view — стандартний рендерить лише таблицю без місця під шапку.
    protected static string $view = 'filament.pages.transactions-list';

    /** Дата, за яку показуємо касу дня і фільтруємо таблицю. Reactive Livewire property. */
    public string $cashDate = '';

    public function mount(): void
    {
        parent::mount();
        if ($this->cashDate === '') {
            $this->cashDate = Carbon::now()->format('Y-m-d');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /** Дані для власного view — плитки каси дня. */
    protected function getViewData(): array
    {
        return [
            'summary' => app(DailyCashService::class)->summarize($this->currentDate()),
        ];
    }

    /**
     * Таблиця під шапкою автоматично фільтрується по обраній даті.
     * Livewire на зміну cashDate перезбирає компонент → замикання отримає свіже значення.
     */
    public function table(Table $table): Table
    {
        return parent::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('date', $this->currentDate()));
    }

    /** Гарантована валідна дата — на випадок, якщо datepicker очистять. */
    protected function currentDate(): string
    {
        if (empty($this->cashDate)) {
            return Carbon::now()->format('Y-m-d');
        }
        try {
            return Carbon::parse($this->cashDate)->format('Y-m-d');
        } catch (\Throwable) {
            return Carbon::now()->format('Y-m-d');
        }
    }
}
