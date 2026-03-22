<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockDocumentResource\Pages;
use App\Models\StockDocument;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\Warehouse;

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
use Filament\Forms\Components\Hidden;
use Illuminate\Support\HtmlString;

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
                            ])
                            ->required()
                            ->live()
                            ->default('receipt'),

                        DateTimePicker::make('operation_date')
                            ->label('Дата та час')
                            ->default(now('Europe/Kiev'))
                            ->timezone('Europe/Kiev')
                            ->required(),

                        Select::make('warehouse_id')
                            ->label('Склад')
                            ->relationship('warehouse', 'name')
                            ->required()
                            ->preload()
                            ->searchable()
                            ->live(), // 🔥 ДОДАНО LIVE, щоб система знала, який склад обрано

                        Select::make('supplier_id')
                            ->label('Постачальник')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->visible(fn (Forms\Get $get) => $get('type') === 'receipt'),

                        Select::make('account_id')
                            ->label('Рахунок (звідки списати)')
                            ->relationship('account', 'name')
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
                            ->schema([

                                Hidden::make('price_manual')
                                    ->default(false)
                                    ->dehydrated(false),

                                // 🔥 ПРИХОВАНІ ПОЛЯ, КУДИ МИ БУДЕМО ЗАПИСУВАТИ ТИП І ID
                                Hidden::make('itemable_type'),
                                Hidden::make('itemable_id'),

                                // 🔥 РОЗУМНИЙ ВИПАДАЮЧИЙ СПИСОК
                                Select::make('item_composite')
                                    ->label('Товар / Продукт')
                                    ->required()
                                    ->searchable()
                                    ->columnSpan(2)
                                    ->dehydrated(false) // Не зберігаємо це віртуальне поле напряму в базу
                                    ->formatStateUsing(function ($state, $record) {
                                        // При редагуванні підтягуємо існуючий товар
                                        if ($record && $record->itemable_type && $record->itemable_id) {
                                            return $record->itemable_type . '||' . $record->itemable_id;
                                        }
                                        return null;
                                    })
                                    ->options(function (Forms\Get $get) {
                                        $warehouseId = $get('../../warehouse_id');
                                        if (!$warehouseId) return [];

                                        $warehouse = Warehouse::find($warehouseId);
                                        $wName = mb_strtolower($warehouse?->name ?? '');

                                        $options = [];

                                        // Якщо в назві складу є слова "упаков" або "госп" -> вантажимо Упаковки
                                        if (str_contains($wName, 'упаков') || str_contains($wName, 'госп')) {
                                            $items = Packaging::all();
                                            foreach ($items as $item) {
                                                $options[Packaging::class . '||' . $item->id] = '📦 ' . $item->name;
                                            }
                                        } else {
                                            // У всіх інших випадках (Кухня, Бар, Продукти) -> вантажимо Інгредієнти
                                            $items = Ingredient::all();
                                            foreach ($items as $item) {
                                                $options[Ingredient::class . '||' . $item->id] = '🍏 ' . $item->name;
                                            }
                                        }

                                        return $options;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        if (!$state) {
                                            $set('itemable_type', null);
                                            $set('itemable_id', null);
                                            $set('price', null);
                                            $set('total_price', 0);
                                            return;
                                        }

                                        // Розбиваємо наш ключ на Клас та ID
                                        [$modelClass, $modelId] = explode('||', $state);

                                        // Записуємо в приховані поля
                                        $set('itemable_type', $modelClass);
                                        $set('itemable_id', $modelId);

                                        $item = $modelClass::find($modelId);
                                        if (!$item) return;

                                        $set('price_manual', false);

                                        $defaultPrice = 0.0;

                                        if ($item instanceof Ingredient) {
                                            $defaultPrice = (float) ($item->average_price ?? 0);
                                        } elseif ($item instanceof Packaging) {
                                            $defaultPrice = (float) ($item->price ?? 0);
                                        }

                                        $defaultPrice = round($defaultPrice, 2);

                                        // Якщо "Надходження", ціну не ставимо. Інакше - ставимо.
                                        if ($get('../../type') === 'receipt') {
                                            $set('price', null);
                                            $set('total_price', 0);
                                        } else {
                                            $set('price', $defaultPrice);
                                            $qty = (float) ($get('qty') ?? 0);
                                            $set('total_price', round($qty * $defaultPrice, 2));
                                        }
                                    }),

                                TextInput::make('qty')
                                    ->label('Кількість')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $price = (float) ($get('price') ?? 0);
                                        $set('total_price', round((float) $state * $price, 2));
                                    }),

                                TextInput::make('price')
                                    ->label('Ціна за 1 од.')
                                    ->numeric()
                                    ->prefix('₴')
                                    ->required()
                                    ->live(debounce: 500)
                                    ->dehydrated(true)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $set('price_manual', true);
                                        $qty = (float) ($get('qty') ?? 0);
                                        $set('total_price', round($qty * (float) $state, 2));
                                    })
                                    ->helperText(function (Forms\Get $get) {
                                        if ($get('../../type') !== 'receipt') {
                                            return null;
                                        }

                                        $modelClass = $get('itemable_type');
                                        $modelId    = $get('itemable_id');

                                        if (!$modelClass || !class_exists($modelClass) || !$modelId) {
                                            return null;
                                        }

                                        $item = $modelClass::find($modelId);
                                        if (!$item) return null;

                                        $lastPrice = 0.0;
                                        if ($item instanceof Ingredient) {
                                            $lastPrice = (float) ($item->average_price ?? 0);
                                        } elseif ($item instanceof Packaging) {
                                            $lastPrice = (float) ($item->price ?? 0);
                                        }

                                        if ($lastPrice <= 0) {
                                            return new HtmlString('<span class="text-[11px] text-gray-500">Перша закупівля (немає історії цін)</span>');
                                        }

                                        $currentPrice = (float) $get('price');
                                        $message = "<span class='text-[11px] text-gray-500'>Попередня ціна: <b>" . number_format($lastPrice, 2) . " ₴</b></span>";

                                        if ($currentPrice > 0) {
                                            $diff = $currentPrice - $lastPrice;
                                            $percent = ($diff / $lastPrice) * 100;

                                            if (round($percent, 1) > 0) {
                                                $message .= "<br><span style='color: #dc2626; font-weight: bold; font-size: 11px;'>Поточна ціна більша на " . round(abs($percent), 1) . "% 📈</span>";
                                            } elseif (round($percent, 1) < 0) {
                                                $message .= "<br><span style='color: #16a34a; font-weight: bold; font-size: 11px;'>Поточна ціна менша на " . round(abs($percent), 1) . "% 📉</span>";
                                            } else {
                                                $message .= "<br><span style='color: #6b7280; font-size: 11px;'>Ціна не змінилася</span>";
                                            }
                                        }

                                        return new HtmlString("<div style='line-height: 1.2; margin-top: 4px;'>{$message}</div>");
                                    }),

                                TextInput::make('total_price')
                                    ->label('Сума')
                                    ->numeric()
                                    ->readOnly()
                                    ->prefix('₴'),
                            ])
                            ->columns(5),
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
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'receipt' => 'success',
                        'write_off' => 'danger',
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