<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderCallResource\Pages;
use App\Models\OrderCall;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

class OrderCallResource extends Resource
{
    protected static ?string $model = OrderCall::class;

    // Іконка та назва для лівого меню
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationLabel = 'Холодна база (Архів)';
    protected static ?string $modelLabel = 'Прозвон';
    protected static ?string $pluralModelLabel = 'Архів прозвонів';
    protected static ?int $navigationSort = 5; // Буде йти одразу під Канбаном

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('status')
                    ->label('Статус')
                    ->options([
                        'new' => '🔵 Треба подзвонити',
                        'no_answer' => '🟡 Не бере слухавку',
                        'thinking' => '🟠 Думає / Перенести',
                        'refused' => '🔴 Відмова',
                        'success' => '🟢 Продовжено',
                    ])
                    ->required(),
                    
                Select::make('refusal_reason')
                    ->label('Причина відмови')
                    ->options([
                        'expensive' => 'Задорого',
                        'taste' => 'Не сподобалось',
                        'vacation' => 'Відпустка / Від\'їзд',
                        'other' => 'Інше',
                    ]),
                    
                DateTimePicker::make('next_call_at')
                    ->label('Коли перетелефонувати?'),
                    
                Textarea::make('comment')
                    ->label('Коментар менеджера')
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Клієнт')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (OrderCall $record): string => $record->client->phone ?? ''),

                Tables\Columns\TextColumn::make('order.end_date')
                    ->label('Дата закінчення')
                    ->date('d.m.Y')
                    ->sortable()
                    ->badge()
                    ->color(fn ($state) => $state < now() ? 'danger' : 'success')
                    // 🔥 ДОДАЄМО ГРАДАЦІЮ (Текст під бейджем)
                    ->description(function (OrderCall $record): ?string {
                        if (!$record->order?->end_date) return null;

                        $endDate = \Carbon\Carbon::parse($record->order->end_date)->startOfDay();
                        $now = now()->startOfDay();
                        $diffDays = $endDate->diffInDays($now, false);

                        if ($diffDays < 0) return 'Ще активний (' . abs($diffDays) . ' дн.)';
                        if ($diffDays == 0) return 'Сьогодні!';
                        if ($diffDays <= 14) return "{$diffDays} дн. тому";
                        if ($diffDays <= 45) return 'Місяць тому';
                        if ($diffDays <= 180) return 'Кілька місяців тому';
                        if ($diffDays <= 365) return 'Півроку тому';
                        
                        return 'Понад рік тому';
                    }),

                // SELECT COLUMN: Менеджер може міняти статус ПРЯМО В ТАБЛИЦІ!
                Tables\Columns\SelectColumn::make('status')
                    ->label('Статус')
                    ->options([
                        'new' => 'Треба подзвонити',
                        'no_answer' => 'Не бере слухавку',
                        'thinking' => 'Думає',
                        'refused' => 'Відмова',
                        'success' => 'Продовжено',
                    ])
                    ->sortable(),

                Tables\Columns\TextColumn::make('refusal_reason')
                    ->label('Причина')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'expensive' => 'Задорого',
                        'taste' => 'Не смачно',
                        'vacation' => 'Відпустка',
                        'other' => 'Інше',
                        default => $state,
                    })
                    ->color('warning'),

                Tables\Columns\TextColumn::make('next_call_at')
                    ->label('Наступний дзвінок')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Коментар')
                    ->limit(30)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 30 ? $state : null;
                    }),
            ])
            ->filters([
                // Фільтр по статусу
                Tables\Filters\SelectFilter::make('status')
                    ->label('Фільтр за статусом')
                    ->options([
                        'new' => 'Треба подзвонити',
                        'no_answer' => 'Не бере слухавку',
                        'thinking' => 'Думає',
                        'refused' => 'Відмова',
                        'success' => 'Продовжено',
                    ]),
                    
                // Фільтр по причині відмови
                Tables\Filters\SelectFilter::make('refusal_reason')
                    ->label('Причина відмови')
                    ->options([
                        'expensive' => 'Задорого',
                        'taste' => 'Не сподобалось',
                        'vacation' => 'Відпустка / Від\'їзд',
                        'other' => 'Інше',
                    ]),

                // 🔥 НОВИЙ ФІЛЬТР ПО ГРАДАЦІЇ ЧАСУ
                Tables\Filters\Filter::make('archived_time')
                    ->form([
                        Forms\Components\Select::make('period')
                            ->label('Коли закінчили замовляти?')
                            ->options([
                                'recently' => 'Нещодавно (до 14 днів)',
                                'month' => 'Близько місяця (15-45 днів)',
                                'few_months' => 'Кілька місяців (до півроку)',
                                'half_year' => 'Півроку і більше',
                            ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! $data['period']) {
                            return $query;
                        }
                        
                        $now = now();
                        
                        return match ($data['period']) {
                            'recently' => $query->whereHas('order', fn($q) => $q->where('end_date', '>=', $now->copy()->subDays(14))),
                            'month' => $query->whereHas('order', fn($q) => $q->whereBetween('end_date', [$now->copy()->subDays(45), $now->copy()->subDays(15)])),
                            'few_months' => $query->whereHas('order', fn($q) => $q->whereBetween('end_date', [$now->copy()->subDays(180), $now->copy()->subDays(46)])),
                            'half_year' => $query->whereHas('order', fn($q) => $q->where('end_date', '<', $now->copy()->subDays(180))),
                        };
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            // За замовчуванням показуємо найновіші дзвінки зверху
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrderCalls::route('/'),
        ];
    }
}