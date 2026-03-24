<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DeliveryCalendarRelationManager extends RelationManager
{
    protected static string $relationship = 'orderDays';
    protected static ?string $title = 'Дні доставки';
    protected static ?string $icon = 'heroicon-o-truck';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([])
            ->paginated(false)
            ->headerActions([])
            ->content(function () {
                return view('filament.resources.orders.delivery-calendar', [
                    'order' => $this->getOwnerRecord(),
                ]);
            });
    }
}
