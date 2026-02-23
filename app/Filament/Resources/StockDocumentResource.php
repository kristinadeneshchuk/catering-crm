<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockDocumentResource\Pages;
use App\Models\StockDocument;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\Dish;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;

use Filament\Tables;
use Filament\Tables\Table;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Hidden;

use Filament\Tables\Columns\TextColumn;

class StockDocumentResource extends Resource
{
    protected static ?string $model = StockDocument::class;
    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Документи складу';
    protected static ?string $breadcrumb = 'Документи складу';
    protected static ?string $pluralModelLabel = 'Документи складу';
    protected static ?string $modelLabel = 'Документ складу';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основні відомості')
                    ->schema([
                        Select::make('type')
                            ->label('Тип запису')
                            ->options([
                                'receipt'   => '⬇️ Надходження на склад',
                                'write_off' => '⬆️ Списання зі складу',
                                'inventory' => '🔄 Інвентаризація',
                            ])
                            ->required()
                            ->live()
                            ->default('receipt'),

                        DateTimePicker::make('operation_date')
                            ->label('Дата та час')
                            ->default(now())
                            ->required(),

                        Select::make('warehouse_id')
                            ->label('Склад')
                            ->relationship('warehouse', 'name')
                            ->required()
                            ->preload()
                            ->searchable(),

                        Select::make('supplier_id')
                            ->label('Постачальник')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (Forms\Get $get) => $get('type') === 'receipt'),

                        Textarea::make('comment')
                            ->label('Коментар')
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Склад запису (Товари)')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->label('')
                            ->addActionLabel('Додати ще позицію')
                            ->key('stock_items_repeater_v4')
                            ->schema([

                                // ✅ Тільки для розрахунків, не шлемо в БД
                                Hidden::make('price_manual')
                                    ->default(false)
                                    ->dehydrated(false),

                                // ✅ Тільки для розрахунків, не шлемо в БД
                                Hidden::make('itemable_hash')
                                    ->default('')
                                    ->dehydrated(false),

                                MorphToSelect::make('itemable')
                                    ->label('Товар / Продукт')
                                    ->types([
                                        MorphToSelect\Type::make(Ingredient::class)
                                            ->titleAttribute('name')
                                            ->label('🍏 Продукт'),

                                        // 🟡 ЗАКОМЕНТОВАНО: Напівфабрикати прибрані зі складу
                                        /* MorphToSelect\Type::make(Dish::class)
                                            ->titleAttribute('name')
                                            ->label('🥣 Напівфабрикат')
                                            ->modifyOptionsQueryUsing(fn ($query) => $query->where('is_semi_finished', true)),
                                        */

                                        MorphToSelect\Type::make(Packaging::class)
                                            ->titleAttribute('name')
                                            ->label('📦 Упаковка'),
                                    ])
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->columnSpan(2)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {

                                        $modelClass = $get('itemable_type');
                                        $modelId    = $get('itemable_id');

                                        if (!$modelClass || !class_exists($modelClass) || !$modelId) {
                                            return;
                                        }

                                        $newHash = $modelClass . ':' . $modelId;
                                        $oldHash = (string) ($get('itemable_hash') ?? '');

                                        if ($newHash === $oldHash) {
                                            return;
                                        }

                                        $set('itemable_hash', $newHash);

                                        $item = $modelClass::find($modelId);
                                        if (!$item) return;

                                        $currentPrice = $get('price');
                                        $manual       = (bool) ($get('price_manual') ?? false);

                                        $shouldAutofill = blank($currentPrice) || $manual === false;

                                        if (!$shouldAutofill) {
                                            return;
                                        }

                                        $set('price_manual', false);

                                        $defaultPrice = 0.0;

                                        if ($item instanceof Ingredient) {
                                            $defaultPrice = (float) ($item->average_price ?? 0);
                                        } 
                                        // 🟡 ЗАКОМЕНТОВАНО: Розрахунок ціни для напівфабрикатів
                                        /*
                                        elseif ($item instanceof Dish) {
                                            $recipeCost = (float) ($item->total_cost ?? 0);
                                            $weightG    = (float) ($item->output_weight ?? 0);
                                            $defaultPrice = $weightG > 0 ? ($recipeCost / $weightG) * 1000 : 0;
                                        } 
                                        */
                                        elseif ($item instanceof Packaging) {
                                            $defaultPrice = (float) ($item->price ?? 0);
                                        }

                                        $defaultPrice = round($defaultPrice, 2);
                                        $set('price', $defaultPrice);

                                        $qty = (float) ($get('qty') ?? 0);
                                        
                                        if ($get('../../type') === 'inventory') {
                                            $systemQty = (float) ($get('system_qty') ?? 0);
                                            $diff = round($qty - $systemQty, 3);
                                            $set('total_price', round($diff * $defaultPrice, 2));
                                        } else {
                                            $set('total_price', round($qty * $defaultPrice, 2));
                                        }

                                        if ($get('../../type') === 'inventory') {
                                            $set('system_qty', $item->stock ?? 0);
                                        }
                                    }),

                                TextInput::make('qty')
                                    ->label(fn (Forms\Get $get) => $get('../../type') === 'inventory' ? 'Факт' : 'Кількість')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $price = (float) ($get('price') ?? 0);

                                        if ($get('../../type') === 'inventory') {
                                            $systemQty = (float) ($get('system_qty') ?? 0);
                                            $diff = round((float) $state - $systemQty, 3);
                                            $set('difference_qty', $diff);

                                            // Різниця в грошах = Різниця кількості * Ціну
                                            $set('total_price', round($diff * $price, 2));

                                            if ($diff > 0) $set('inventory_status', 'Надлишок');
                                            elseif ($diff < 0) $set('inventory_status', 'Нестача');
                                            else $set('inventory_status', 'ОК');
                                        } else {
                                            $set('total_price', round((float) $state * $price, 2));
                                        }
                                    }),

                                TextInput::make('price')
                                    ->label('Ціна за 1 од.')
                                    ->numeric()
                                    ->prefix('₴')
                                    ->required()
                                    ->live(onBlur: true)
                                    ->dehydrated(true)
                                    ->hidden(fn (Forms\Get $get) => $get('../../type') === 'inventory')
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $set('price_manual', true);

                                        $qty = (float) ($get('qty') ?? 0);
                                        $set('total_price', round($qty * (float) $state, 2));
                                    }),

                                TextInput::make('total_price')
                                    ->label(fn (Forms\Get $get) => $get('../../type') === 'inventory' ? 'Різниця (₴)' : 'Сума')
                                    ->numeric()
                                    ->readOnly()
                                    ->prefix('₴'),

                                TextInput::make('system_qty')
                                    ->label('В прогр.')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated(false) // ✅ Тільки для розрахунків
                                    ->visible(fn (Forms\Get $get) => $get('../../type') === 'inventory'),

                                TextInput::make('difference_qty')
                                    ->label('Різниця (кількість)')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated(false) // ✅ Тільки для розрахунків
                                    ->visible(fn (Forms\Get $get) => $get('../../type') === 'inventory'),

                                TextInput::make('inventory_status')
                                    ->label('Статус')
                                    ->readOnly()
                                    ->dehydrated(false) // ✅ Тільки для розрахунків
                                    ->extraInputAttributes(fn ($state) => [
                                        'style' => 'font-weight: bold; color: ' . match ($state) {
                                            'Надлишок' => '#22c55e',
                                            'Нестача' => '#ef4444',
                                            default => '#6b7280',
                                        },
                                    ])
                                    ->visible(fn (Forms\Get $get) => $get('../../type') === 'inventory'),
                            ])
                            ->columns(6),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('№')->sortable(),
                TextColumn::make('operation_date')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'receipt' => 'Надходження',
                        'write_off' => 'Списання',
                        'inventory' => 'Інвентаризація',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'receipt' => 'success',
                        'write_off' => 'danger',
                        'inventory' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('total_sum')->label('Сума')->money('UAH'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListStockDocuments::route('/'),
            'create' => Pages\CreateStockDocument::route('/create'),
            'edit'   => Pages\EditStockDocument::route('/{record}/edit'),
        ];
    }
}