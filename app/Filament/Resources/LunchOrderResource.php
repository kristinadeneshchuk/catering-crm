<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LunchOrderResource\Pages;
use App\Models\LunchOrder;
use App\Models\LunchOrderLine;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Корпоративні обіди з Lunch Hub: скільки обідів якій компанії на який день.
 *
 * Лише перегляд — дані приходять знімком із Lunch Hub після дедлайну
 * (POST /api/lunch/orders); правити їх тут немає сенсу, наступний знімок перезапише.
 */
class LunchOrderResource extends Resource
{
    protected static ?string $model = LunchOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Обіди (компанії)';
    protected static ?string $modelLabel = 'Обіди компанії';
    protected static ?string $pluralModelLabel = 'Обіди (компанії)';
    protected static ?int $navigationSort = 6;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return \App\Support\SchemaReady::has('lunch_orders')
            && ($user?->isAdmin() || $user?->isManager());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('lines'))
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('День')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('company_name')->label('Компанія')->searchable()
                    ->description(fn (LunchOrder $o) => $o->address),
                Tables\Columns\TextColumn::make('sets')->label('Обідів')->alignCenter()->weight('bold')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')),
                Tables\Columns\TextColumn::make('dishes')->label('Страви')->wrap()
                    ->state(fn (LunchOrder $o) => new HtmlString($o->lines
                        ->sortBy(fn (LunchOrderLine $l) => array_search($l->kind, ['main', 'light', 'extra'], true))
                        ->map(fn (LunchOrderLine $l) => e($l->name).' — <b>'.$l->qty.'</b>'
                            .($l->weight_g ? ' × '.$l->weight_g.' г' : '')
                            .($l->kind === 'extra' ? ' <span style="opacity:.6">(доплата)</span>' : '')
                            .(! $l->dish_id || ! $l->weight_g ? ' <span style="color:#dc2626;font-weight:700">— без '.(! $l->dish_id ? 'страви CRM' : 'ваги').', у кухонні документи не потрапляє</span>' : ''))
                        ->implode('<br>')))
                    ->html(),
                Tables\Columns\TextColumn::make('delivery_window')->label('Доставка')->placeholder('—')
                    ->description(fn (LunchOrder $o) => $o->contact),
                Tables\Columns\TextColumn::make('amount')->label('Сума')->money('UAH')->alignEnd()
                    ->visible(fn () => (bool) auth()->user()?->isAdmin())
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')->money('UAH')),
                Tables\Columns\TextColumn::make('received_at')->label('Отримано')->dateTime('d.m H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('upcoming')->label('Сьогодні й далі')->default()
                    ->query(fn (Builder $query) => $query->whereDate('date', '>=', now()->toDateString())),
                Tables\Filters\SelectFilter::make('company_id')->label('Компанія')
                    ->options(fn () => LunchOrder::query()->orderBy('company_name')->pluck('company_name', 'company_id')->all()),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLunchOrders::route('/'),
        ];
    }
}
