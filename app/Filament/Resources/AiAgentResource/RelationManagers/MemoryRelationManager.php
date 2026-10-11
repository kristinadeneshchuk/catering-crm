<?php

namespace App\Filament\Resources\AiAgentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Памʼять агента — що вже зроблено й проаналізовано. Лише перегляд. */
class MemoryRelationManager extends RelationManager
{
    protected static string $relationship = 'memory';
    protected static ?string $title = 'Памʼять';

    public function isReadOnly(): bool { return true; }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                Tables\Columns\TextColumn::make('key')->label('Ключ')->searchable(),
                Tables\Columns\TextColumn::make('value')->label('Значення')
                    ->formatStateUsing(fn ($state) => mb_strimwidth(is_string($state) ? $state : json_encode($state, JSON_UNESCAPED_UNICODE), 0, 160, '…'))->wrap(),
                Tables\Columns\TextColumn::make('updated_at')->label('Оновлено')->since(),
            ]);
    }
}
