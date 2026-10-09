<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankOperationResource\Pages;
use App\Models\Account;
use App\Models\BankOperation;
use Carbon\Carbon;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Операції з банку (monobank). Гроші бізнесу — лише супер адмін.
 * Тільки перегляд: операції приходять з банку, руками не правляться.
 */
class BankOperationResource extends Resource
{
    protected static ?string $model = BankOperation::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?string $navigationLabel = 'Банк';
    protected static ?string $modelLabel = 'Операція банку';
    protected static ?string $pluralModelLabel = 'Банк';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canViewAny(): bool { return static::canAccess(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('operated_at', 'desc')
            ->columns([
                TextColumn::make('operated_at')->label('Час')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('account.name')->label('Рахунок')->badge()->color('gray'),
                Tables\Columns\IconColumn::make('is_internal')
                    ->label('Між своїми')
                    ->tooltip('Переказ між своїми рахунками — не витрата бізнесу')
                    ->state(fn (BankOperation $r) => in_array($r->id, static::internalIds(), true))
                    ->boolean()
                    ->trueIcon('heroicon-o-arrows-right-left')->trueColor('info')
                    ->falseIcon('')->alignCenter(),
                TextColumn::make('amount')
                    ->label('Сума')
                    ->money('UAH')
                    ->sortable()
                    ->weight('bold')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->summarize([
                        Sum::make()->label('Надходження')->money('UAH')->query(fn ($query) => $query->where('amount', '>', 0)),
                        Sum::make()->label('Витрати')->money('UAH')->query(fn ($query) => $query->where('amount', '<', 0)),
                        Sum::make()->label('Разом')->money('UAH'),
                    ]),
                TextColumn::make('counter_name')
                    ->label('Контрагент')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn (BankOperation $r) => $r->counter_edrpou ? 'ЄДРПОУ/ІПН ' . $r->counter_edrpou : null)
                    ->wrap(),
                TextColumn::make('description')
                    ->label('Призначення')
                    ->searchable()
                    ->limit(80)
                    ->tooltip(fn (BankOperation $r) => trim($r->description . ' ' . $r->comment))
                    ->wrap(),
                TextColumn::make('balance_after')->label('Залишок')->money('UAH')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('month')
                    ->label('Місяць')
                    ->options(fn () => static::monthOptions())
                    ->default(now()->format('Y-m'))
                    ->query(function (Builder $q, array $data) {
                        if (!$data['value']) return $q;
                        $start = Carbon::createFromFormat('Y-m-d', $data['value'] . '-01')->startOfDay();
                        return $q->whereBetween('operated_at', [$start, $start->copy()->endOfMonth()]);
                    }),
                SelectFilter::make('account_id')
                    ->label('Рахунок')
                    ->options(fn () => Account::withBankOnly()->whereNotNull('mono_token')->pluck('name', 'id')),
                SelectFilter::make('internal')
                    ->label('Перекази між своїми')
                    ->options(['hide' => 'Без переказів між своїми', 'only' => 'Лише перекази між своїми'])
                    ->placeholder('Усі операції')
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'hide'  => $q->internal(false),
                        'only'  => $q->internal(),
                        default => $q,
                    }),
                                SelectFilter::make('direction')
                    ->label('Напрям')
                    ->options(['in' => 'Надходження', 'out' => 'Витрати'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'in'    => $q->where('amount', '>', 0),
                        'out'   => $q->where('amount', '<', 0),
                        default => $q,
                    }),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->paginated([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    /** id переказів між своїми — один запит на сторінку, а не на кожен рядок. */
    public static function internalIds(): array
    {
        return once(fn () => BankOperation::query()->internal()->pluck('id')->all());
    }

        /** Місяці, за які є операції, плюс поточний — нові першими. */
    public static function monthOptions(): array
    {
        $first = BankOperation::min('operated_at');
        $cursor = $first ? Carbon::parse($first)->startOfMonth() : now()->startOfMonth();
        $months = collect();
        while ($cursor->lte(now())) {
            $months->push($cursor->format('Y-m'));
            $cursor->addMonth();
        }
        $months = $months->reverse();

        return $months->mapWithKeys(fn ($m) => [
            $m => Carbon::createFromFormat('Y-m-d', $m . '-01')->locale('uk')->translatedFormat('F Y'),
        ])->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankOperations::route('/'),
        ];
    }
}
