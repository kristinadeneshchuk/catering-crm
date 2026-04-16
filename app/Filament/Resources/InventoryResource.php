<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\InventoryResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\InventoryResource\Pages;
use App\Models\Inventory;
use App\Models\Ingredient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Fieldset;
use Filament\Forms\Components\Placeholder;
use Illuminate\Support\HtmlString;

class InventoryResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Inventory::class;
    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Інвентаризації';
    protected static ?string $pluralModelLabel = 'Інвентаризації';
    protected static ?string $modelLabel = 'Інвентаризація';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Статистика прогресу (тільки на сторінці редагування)
                Placeholder::make('progress_stats')
                    ->label('')
                    ->hidden(fn ($record) => $record === null)
                    ->columnSpanFull()
                    ->content(function ($record) {
                        if (!$record) return '';
                        $record->refresh(); // підтягуємо актуальні дані після змін у таблиці
                        $items    = $record->items()->get();
                        $total    = $items->count();
                        $filled   = $items->whereNotNull('actual_qty')->count();
                        $pct      = $total > 0 ? round($filled / $total * 100) : 0;
                        $surplus  = number_format((float)($record->total_surplus  ?? 0), 2, '.', ' ');
                        $shortage = number_format((float)($record->total_shortage ?? 0), 2, '.', ' ');
                        $withDiff = $items->filter(fn ($i) => $i->actual_qty !== null && round($i->actual_qty - $i->expected_qty, 3) != 0)->count();
                        $statusLabel = $record->status === 'completed' ? 'Проведена' : 'Відкрита';
                        $statusColor = $record->status === 'completed' ? '#4ade80' : '#fbbf24';

                        $barColor = $pct >= 100 ? '#4ade80' : ($pct >= 50 ? '#60a5fa' : '#fbbf24');

                        return new HtmlString('
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:4px;">
                                <div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:14px 18px;background:rgba(255,255,255,0.03);">
                                    <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Позицій</div>
                                    <div style="font-size:26px;font-weight:900;line-height:1;">' . $total . '</div>
                                    <div style="margin-top:8px;height:4px;background:rgba(255,255,255,0.08);border-radius:4px;">
                                        <div style="width:' . $pct . '%;height:4px;background:' . $barColor . ';border-radius:4px;transition:width .3s;"></div>
                                    </div>
                                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Заповнено: ' . $filled . ' / ' . $total . ' (' . $pct . '%)</div>
                                </div>

                                <div style="border:1px solid rgba(251,191,36,0.25);border-radius:12px;padding:14px 18px;background:rgba(251,191,36,0.05);">
                                    <div style="font-size:11px;color:#fbbf24;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">З відхиленням</div>
                                    <div style="font-size:26px;font-weight:900;line-height:1;color:#fbbf24;">' . $withDiff . '</div>
                                    <div style="font-size:11px;color:#9ca3af;margin-top:12px;">позицій мають різницю</div>
                                </div>

                                <div style="border:1px solid rgba(74,222,128,0.25);border-radius:12px;padding:14px 18px;background:rgba(74,222,128,0.05);">
                                    <div style="font-size:11px;color:#4ade80;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Надлишок</div>
                                    <div style="font-size:26px;font-weight:900;line-height:1;color:#4ade80;">' . $surplus . ' ₴</div>
                                    <div style="font-size:11px;color:#9ca3af;margin-top:12px;">на суму</div>
                                </div>

                                <div style="border:1px solid rgba(248,113,113,0.25);border-radius:12px;padding:14px 18px;background:rgba(248,113,113,0.05);">
                                    <div style="font-size:11px;color:#f87171;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Нестача</div>
                                    <div style="font-size:26px;font-weight:900;line-height:1;color:#f87171;">' . $shortage . ' ₴</div>
                                    <div style="font-size:11px;color:#9ca3af;margin-top:12px;">на суму</div>
                                </div>
                            </div>
                        ');
                    }),

                Section::make('Параметри інвентаризації')
                    ->schema([
                        DateTimePicker::make('operation_date')
                            ->label('Дата та час проведення')
                            ->default(now())
                            ->required(),

                        ToggleButtons::make('type')
                            ->label('Тип інвентаризації')
                            ->options([
                                'full' => 'Повна (всі товари на складі)',
                                'partial' => 'Часткова (обрані категорії)',
                            ])
                            ->default('full')
                            ->inline()
                            ->colors([
                                'full' => 'success',
                                'partial' => 'warning',
                            ])
                            ->live()
                            ->required()
                            ->columnSpanFull(),

                        Fieldset::make('Налаштування часткової інвентаризації')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'partial')
                            ->columnSpanFull()
                            ->schema([
                                Toggle::make('include_packagings')
                                    ->label('Додати упаковку та госптовари')
                                    ->default(false)
                                    ->columnSpanFull(),

                                CheckboxList::make('selected_groups')
                                    ->label('Категорії продуктів')
                                    ->options(function () {
                                        return Ingredient::whereNotNull('group')
                                            ->distinct()
                                            ->pluck('group', 'group')
                                            ->toArray();
                                    })
                                    ->columns(4)
                                    ->gridDirection('row')
                                    ->bulkToggleable()
                                    ->columnSpanFull(),
                            ]),

                        Textarea::make('comment')
                            ->label('Коментар')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('№')
                    ->sortable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('operation_date')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'full' ? 'Повна' : 'Часткова')
                    ->color(fn ($state) => $state === 'full' ? 'primary' : 'warning'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'draft' ? 'Відкрита' : 'Проведена')
                    ->color(fn ($state) => $state === 'draft' ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Позицій')
                    ->getStateUsing(fn ($record) => $record->items()->count())
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('total_surplus')
                    ->label('Надлишок')
                    ->formatStateUsing(fn ($state) => $state > 0 ? '+' . number_format((float)$state, 2, '.', ' ') . ' ₴' : '—')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_shortage')
                    ->label('Нестача')
                    ->formatStateUsing(fn ($state) => $state > 0 ? '-' . number_format((float)$state, 2, '.', ' ') . ' ₴' : '—')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'gray')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Коментар')
                    ->limit(40)
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->striped()
            ->actions([
                Tables\Actions\EditAction::make()->label('Відкрити')->icon('heroicon-o-folder-open'),
            ]);
    }

    // 🔥 ДОДАНО: Підключення таблиці з товарами
    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}