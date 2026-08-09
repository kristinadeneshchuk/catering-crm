<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Залишок моделі по філіях. Кількість тут — це фізичні екземпляри на полиці,
 * саме вона визначає, скільки одночасних бронювань витримає позиція.
 */
class InventoryRelationManager extends RelationManager
{
    protected static string $relationship = 'branches';

    protected static ?string $title = 'Залишки по філіях';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('qty')
                ->label('Екземплярів')
                ->numeric()->minValue(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Філія'),
                TextColumn::make('city.name')->label('Місто'),
                TextColumn::make('address')->label('Адреса')->toggleable(),
                TextColumn::make('pivot.qty')->label('Екземплярів')->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Додати філію')
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action) => [
                        $action->getRecordSelect(),
                        TextInput::make('qty')->label('Екземплярів')->numeric()->default(1)->required(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Кількість'),
                DetachAction::make()->label('Прибрати'),
            ]);
    }
}
