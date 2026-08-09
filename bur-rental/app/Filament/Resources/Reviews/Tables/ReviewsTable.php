<?php

namespace App\Filament\Resources\Reviews\Tables;

use App\Models\Branch;
use App\Models\City;
use App\Models\Product;
use App\Models\Review;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ReviewsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('published_at')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('author')->label('Автор')->searchable(),

                TextColumn::make('reviewable')
                    ->label('До чого')
                    ->state(fn (Review $r) => $r->reviewable?->name ?? '—')
                    ->description(fn (Review $r) => match ($r->reviewable_type) {
                        Product::class => 'товар',
                        Branch::class => 'філія',
                        City::class => 'місто',
                        default => '',
                    }),

                TextColumn::make('rating')->label('Оцінка')
                    ->formatStateUsing(fn (int $state) => str_repeat('★', $state))
                    ->color('warning')
                    ->sortable(),

                TextColumn::make('body')->label('Текст')->limit(70),

                TextColumn::make('source')->label('Джерело')->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'google' ? 'Google' : 'Сайт'),
            ])
            ->defaultSort('published_at', 'desc')
            ->filters([
                SelectFilter::make('source')->label('Джерело')->options(['site' => 'Сайт', 'google' => 'Google']),
                SelectFilter::make('rating')->label('Оцінка')->options([5 => '5', 4 => '4', 3 => '3', 2 => '2', 1 => '1']),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
