<?php

namespace App\Filament\Resources\Articles\Tables;

use App\Models\Article;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->label('Заголовок')->searchable()->limit(60)->weight('bold'),
                TextColumn::make('kit.name')->label('Веде в комплект')->placeholder('—'),
                TextColumn::make('published_at')->label('Опубліковано')->date('d.m.Y')->sortable(),
                TextColumn::make('reading_minutes')->label('Хв')
                    ->state(fn (Article $r) => $r->reading_minutes),
                IconColumn::make('published')->label('На сайті')->boolean(),
            ])
            ->defaultSort('position')
            ->reorderable('position')
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
