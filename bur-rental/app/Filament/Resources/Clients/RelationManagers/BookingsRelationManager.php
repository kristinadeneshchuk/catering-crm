<?php

namespace App\Filament\Resources\Clients\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Оренди клієнта. Редагування — у самій броні, тут тільки перелік і перехід:
 * дублювати форму броні означало б завести другу копію логіки статусів.
 */
class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Оренди';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->label('Номер')->weight('bold'),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Booking::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning',
                        'confirmed' => 'info',
                        'issued' => 'primary',
                        'closed' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('date_from')
                    ->label('Строк')
                    ->state(fn (Booking $r) => $r->date_from->format('d.m.Y').' — '.$r->date_to->format('d.m.Y'))
                    ->description(fn (Booking $r) => $r->days.' дн.'),

                TextColumn::make('branch.name')->label('Філія')->placeholder('—'),

                TextColumn::make('rent_total')
                    ->label('Оренда')
                    ->money('UAH', 1)
                    ->summarize(Sum::make()->label('Разом')->money('UAH', 1)),

                TextColumn::make('deposit_total')->label('Застава')->money('UAH', 1),
            ])
            ->defaultSort('date_from', 'desc')
            ->recordActions([
                Action::make('open')
                    ->label('Відкрити')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Booking $record) => BookingResource::getUrl('edit', ['record' => $record])),
            ]);
    }
}
