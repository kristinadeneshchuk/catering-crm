<?php

namespace App\Filament\Resources\Bookings\Tables;

use App\Filament\Resources\Bookings\Schemas\BookingForm;
use App\Models\Booking;
use App\Services\BookingWorkflow;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Номер')
                    ->searchable()
                    ->copyable()
                    ->weight('bold'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => BookingForm::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning',
                        'confirmed' => 'info',
                        'issued' => 'primary',
                        'closed' => 'success',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('client')
                    ->label('Клієнт')
                    ->state(fn (Booking $r) => $r->company ?: $r->name ?: '—')
                    ->description(fn (Booking $r) => $r->phone)
                    ->searchable(['name', 'company', 'phone']),

                TextColumn::make('date_from')
                    ->label('Строк')
                    ->state(fn (Booking $r) => $r->date_from->format('d.m').' — '.$r->date_to->format('d.m'))
                    ->description(fn (Booking $r) => $r->days.' дн.')
                    ->sortable(),

                TextColumn::make('branch.name')->label('Філія')->sortable(),

                TextColumn::make('items_count')
                    ->label('Позицій')
                    ->counts('items')
                    ->badge(),

                TextColumn::make('payable')
                    ->label('До сплати')
                    ->state(fn (Booking $r) => $r->payable)
                    ->money('UAH', 1)
                    ->description(fn (Booking $r) => 'з них застава '.number_format($r->deposit_total, 0, ',', ' ').' ₴'),

                TextColumn::make('created_at')
                    ->label('Створена')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Статус')->options(BookingForm::statuses()),
                SelectFilter::make('branch')->label('Філія')->relationship('branch', 'name'),

                // Два фільтри, якими менеджер користується щоранку.
                Filter::make('issue_today')
                    ->label('Видача сьогодні')
                    ->query(fn (Builder $q) => $q->whereDate('date_from', today())
                        ->whereIn('status', ['new', 'confirmed'])),

                Filter::make('return_today')
                    ->label('Повернення сьогодні')
                    ->query(fn (Builder $q) => $q->whereDate('date_to', today())->where('status', 'issued')),

                Filter::make('overdue')
                    ->label('Прострочені')
                    ->query(fn (Builder $q) => $q->whereDate('date_to', '<', today())->where('status', 'issued')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('confirm')
                        ->label('Підтвердити')
                        ->icon('heroicon-o-check')
                        ->visible(fn (Booking $r) => $r->status === 'new')
                        ->action(fn (Booking $r) => app(BookingWorkflow::class)->confirm($r)),

                    Action::make('issue')
                        ->label('Видати')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->visible(fn (Booking $r) => in_array($r->status, ['new', 'confirmed'], true))
                        ->action(fn (Booking $r) => app(BookingWorkflow::class)->issue($r)),

                    Action::make('close')
                        ->label('Прийняти повернення')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->visible(fn (Booking $r) => $r->status === 'issued')
                        ->schema([
                            DatePicker::make('returned_on')
                                ->label('Фактична дата повернення')
                                ->default(today())
                                ->required()
                                ->helperText('Здали раніше — перерахуємо тариф і звільнимо дні. Пізніше — доберемо прострочення за базовим тарифом.'),
                        ])
                        ->action(function (Booking $r, array $data) {
                            $before = $r->rent_total;
                            $after = app(BookingWorkflow::class)->close($r, $data['returned_on'])->rent_total;

                            Notification::make()
                                ->title('Техніку прийнято')
                                ->body($before === $after
                                    ? 'Сума не змінилася.'
                                    : 'Оренду перераховано: '.number_format($before, 0, ',', ' ').' → '.number_format($after, 0, ',', ' ').' ₴')
                                ->success()
                                ->send();
                        }),

                    Action::make('cancel')
                        ->label('Скасувати')
                        ->icon('heroicon-o-x-mark')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalDescription('Дати повернуться в календар і позицію знову можна буде забронювати.')
                        ->visible(fn (Booking $r) => ! in_array($r->status, ['closed', 'cancelled'], true))
                        ->action(fn (Booking $r) => app(BookingWorkflow::class)->cancel($r)),

                    EditAction::make(),
                ]),
            ]);
    }
}
