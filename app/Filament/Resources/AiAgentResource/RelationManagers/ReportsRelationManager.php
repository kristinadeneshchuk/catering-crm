<?php

namespace App\Filament\Resources\AiAgentResource\RelationManagers;

use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Звіти агента: що зробив, що пропустив, питання. */
class ReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'reports';
    protected static ?string $title = 'Звіти';

    public function isReadOnly(): bool { return true; }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\TextEntry::make('title')->label('')->weight('bold'),
            Infolists\Components\TextEntry::make('body')->label('')->markdown()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Коли')->dateTime('d.m H:i'),
                Tables\Columns\TextColumn::make('level')->label('')->badge()
                    ->color(fn ($state) => ['warn' => 'warning', 'error' => 'danger'][$state] ?? 'gray'),
                Tables\Columns\TextColumn::make('title')->label('Звіт')->wrap(),
            ])
            ->actions([Tables\Actions\ViewAction::make()->label('')]);
    }
}
