<?php

namespace App\Filament\Resources\Faqs\Tables;

use App\Filament\Resources\Faqs\Schemas\FaqForm;
use App\Models\Faq;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FaqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')->label('Питання')->searchable()->limit(70),

                TextColumn::make('where')
                    ->label('Де показується')
                    ->state(fn (Faq $r) => $r->scope
                        ? (FaqForm::scopes()[$r->scope] ?? $r->scope)
                        : ($r->faqable?->name ?? '—')),

                TextColumn::make('position')->label('Порядок')->sortable(),
            ])
            ->defaultSort('position')
            ->filters([
                SelectFilter::make('scope')->label('Розділ')->options(FaqForm::scopes()),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
