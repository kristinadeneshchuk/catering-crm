<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use App\Services\BookingWorkflow;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Робочий стіл дня: усе, що треба видати або прийняти сьогодні, плюс борги.
 * Дії доступні прямо звідси — менеджер не має шукати бронь у загальному списку.
 */
class TodayBoard extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return 'Сьогодні: видача, приймання, борги';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()
                    ->with('branch')
                    ->where(fn (Builder $q) => $q
                        ->where(fn (Builder $i) => $i->whereDate('date_from', today())->whereIn('status', ['new', 'confirmed']))
                        ->orWhere(fn (Builder $r) => $r->whereDate('date_to', today())->where('status', 'issued'))
                        ->orWhere(fn (Builder $o) => $o->whereDate('date_to', '<', today())->where('status', 'issued'))
                    )
                    ->orderBy('date_from')
            )
            ->columns([
                TextColumn::make('what')
                    ->label('Що робити')
                    ->badge()
                    ->state(fn (Booking $r) => match (true) {
                        $r->status === 'issued' && $r->date_to->isBefore(today()) => 'Прострочено',
                        $r->status === 'issued' => 'Прийняти',
                        default => 'Видати',
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Прострочено' => 'danger',
                        'Прийняти' => 'info',
                        default => 'primary',
                    }),

                TextColumn::make('number')->label('Бронь')->searchable(),

                TextColumn::make('client')
                    ->label('Клієнт')
                    ->state(fn (Booking $r) => $r->company ?: $r->name ?: '—')
                    ->description(fn (Booking $r) => $r->phone),

                TextColumn::make('branch.name')->label('Філія'),

                TextColumn::make('date_to')
                    ->label('Повернення')
                    ->date('d.m.Y')
                    // Повернення сьогодні — ще не прострочення, тому isBefore, а не isPast.
                    ->description(fn (Booking $r) => $r->status === 'issued' && $r->date_to->isBefore(today())
                        ? 'прострочення '.(int) $r->date_to->diffInDays(today()).' дн.'
                        : null),

                TextColumn::make('payable')
                    ->label('До сплати')
                    ->state(fn (Booking $r) => $r->payable)
                    ->money('UAH', 1),
            ])
            ->recordActions([
                Action::make('issue')
                    ->label('Видати')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->visible(fn (Booking $r) => in_array($r->status, ['new', 'confirmed'], true))
                    ->action(fn (Booking $r) => app(BookingWorkflow::class)->issue($r)),

                Action::make('close')
                    ->label('Прийняти')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn (Booking $r) => $r->status === 'issued')
                    ->schema([
                        DatePicker::make('returned_on')
                            ->label('Фактична дата повернення')
                            ->default(today())->required(),
                    ])
                    ->action(fn (Booking $r, array $data) => app(BookingWorkflow::class)->close($r, $data['returned_on'])),
            ])
            ->emptyStateHeading('На сьогодні нічого')
            ->emptyStateDescription('Видач і повернень не заплановано, боргів немає.');
    }
}
