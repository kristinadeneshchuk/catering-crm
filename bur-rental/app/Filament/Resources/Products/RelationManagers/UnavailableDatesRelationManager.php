<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Зайняті дати. Сюди потрапляють і броні з сайту, і ручні блокування —
 * сервіс, ремонт, техніка поїхала на об'єкт без броні.
 */
class UnavailableDatesRelationManager extends RelationManager
{
    protected static string $relationship = 'unavailableDates';

    protected static ?string $title = 'Зайняті дати';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('branch_id')
                ->label('Філія')
                ->options(fn () => $this->getOwnerRecord()->branches->pluck('name', 'id'))
                ->required(),

            DatePicker::make('date')->label('Дата')->required(),

            Select::make('reason')
                ->label('Причина')
                ->options(['rented' => 'В оренді', 'service' => 'Сервіс / ремонт'])
                ->default('service')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('branch.name')->label('Філія'),
                TextColumn::make('reason')
                    ->label('Причина')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'service' => 'Сервіс',
                        default => 'В оренді',
                    })
                    ->color(fn (string $state) => $state === 'service' ? 'warning' : 'danger'),
            ])
            ->defaultSort('date')
            ->filters([
                SelectFilter::make('branch')->label('Філія')->relationship('branch', 'name'),
                SelectFilter::make('reason')->label('Причина')->options([
                    'rented' => 'В оренді',
                    'service' => 'Сервіс / ремонт',
                ]),
            ])
            ->headerActions([
                CreateAction::make()->label('Заблокувати дату'),
            ])
            ->recordActions([DeleteAction::make()->label('Звільнити')])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
