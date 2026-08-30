<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Filament\Resources\Leads\Schemas\LeadForm;
use App\Models\Lead;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LeadsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Коли')->dateTime('d.m.Y H:i')->sortable(),

                TextColumn::make('kind')->label('Тип')->badge()
                    ->formatStateUsing(fn (string $state) => LeadForm::kinds()[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'b2b' ? 'success' : 'gray'),

                TextColumn::make('name')
                    ->label('Хто')
                    ->state(fn (Lead $r) => $r->company ?: $r->name ?: '—')
                    ->description(fn (Lead $r) => $r->phone ?: $r->email),

                TextColumn::make('context')->label('Звідки')->toggleable()->limit(40),

                // Реклама, з якої прийшов клієнт. Без цієї колонки неможливо
                // сказати, які оголошення дають дзвінки, а які — покази.
                TextColumn::make('campaign')
                    ->label('Реклама')
                    ->state(fn (Lead $r) => $r->campaign_label)
                    ->placeholder('прямий захід')
                    ->toggleable(),
                TextColumn::make('message')->label('Повідомлення')->limit(60)->toggleable(),

                TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn (string $state) => LeadForm::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'new' => 'warning',
                        'in_progress' => 'info',
                        'done' => 'success',
                        default => 'gray',
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('kind')->label('Тип')->options(LeadForm::kinds()),
                SelectFilter::make('status')->label('Статус')->options(LeadForm::statuses()),
            ])
            ->recordActions([
                // Заявку обробляють у два кліки: взяв — закрив. Форма для цього зайва.
                Action::make('take')
                    ->label('В роботу')
                    ->icon('heroicon-o-play')
                    ->visible(fn (Lead $r) => $r->status === 'new')
                    ->action(fn (Lead $r) => $r->update(['status' => 'in_progress'])),

                Action::make('done')
                    ->label('Оброблена')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (Lead $r) => $r->status !== 'done')
                    ->action(fn (Lead $r) => $r->update(['status' => 'done'])),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
