<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\StockDocumentResource\Pages;
use App\Models\StockDocument;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\Warehouse;

use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Enums\FiltersLayout;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\HtmlString;

use Filament\Tables\Columns\TextColumn;

class StockDocumentResource extends Resource
{
    use RestrictCookAccess;
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
                            ->live(),

                        Select::make('item_category')
                            ->label('Тип товарів')
                            ->options([
                                'ingredient' => 'Продукти / Інгредієнти',
                                'packaging'  => 'Упаковка / Госптовари',
                            ])
                            ->required()
                            ->live()
                            ->dehydrated(false)
                            ->visible(fn (Forms\Get $get) => $get('warehouse_id') !== null),

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

                        Toggle::make('is_paid')
                            ->label('Оплачено')
                            ->helperText('Транзакція буде створена тільки після позначення як "Оплачено"')
                            ->default(false)
                            ->onColor('success')
                            ->columnSpanFull(),

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
                            ->live()
                            ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                                $items = $get('items') ?? [];
                                $count = count($items);
                                $sum   = collect($items)->sum(fn ($i) => (float) ($i['total_price'] ?? 0));
                                $set('items_summary', $count . '||' . $sum);
                            })
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
                                        $category = $get('../../item_category');
                                        if (!$category) return [];

                                        if ($category === 'packaging') {
                                            return Packaging::orderBy('name')->get()
                                                ->mapWithKeys(fn ($item) => [
                                                    Packaging::class . '||' . $item->id => $item->name,
                                                ])->all();
                                        }

                                        return Ingredient::orderBy('name')->get()
                                            ->mapWithKeys(fn ($item) => [
                                                Ingredient::class . '||' . $item->id => $item->name,
                                            ])->all();
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
                                                $message .= "<br><span style='color: #dc2626; font-weight: bold; font-size: 11px;'>Поточна ціна більша на " . round(abs($percent), 1) . "% ↑</span>";
                                            } elseif (round($percent, 1) < 0) {
                                                $message .= "<br><span style='color: #16a34a; font-weight: bold; font-size: 11px;'>Поточна ціна менша на " . round(abs($percent), 1) . "% ↓</span>";
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

                        Forms\Components\Hidden::make('items_summary')
                            ->dehydrated(false),

                        Forms\Components\Placeholder::make('items_totals')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                $items = $get('items') ?? [];
                                $count = count($items);
                                $sum   = collect($items)->sum(fn ($i) => (float) ($i['total_price'] ?? 0));

                                if ($count === 0) return new HtmlString('');

                                return new HtmlString(
                                    "<div style='display:flex;gap:24px;align-items:center;padding:10px 14px;"
                                    . "background:linear-gradient(145deg,#0f172a,#1a2436);border:1px solid #1e293b;"
                                    . "border-radius:10px;margin-top:4px;'>"
                                    . "<span style='font-size:12px;color:#64748b;'>Позицій: "
                                    . "<b style='color:#e2e8f0;font-size:14px;'>{$count}</b></span>"
                                    . "<span style='color:#334155;'>|</span>"
                                    . "<span style='font-size:12px;color:#64748b;'>Загальна сума: "
                                    . "<b style='color:#22c55e;font-size:16px;'>" . number_format($sum, 2, '.', ' ') . " ₴</b></span>"
                                    . "</div>"
                                );
                            })
                            ->live(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('№')
                    ->sortable(),

                TextColumn::make('operation_date')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'receipt'   => 'Надходження',
                        'write_off' => 'Списання',
                        default     => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'receipt'   => 'success',
                        'write_off' => 'danger',
                        default     => 'gray',
                    }),

                TextColumn::make('supplier.name')
                    ->label('Постачальник')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('total_sum')
                    ->label('Сума')
                    ->money('UAH')
                    ->sortable()
                    ->summarize(
                        Tables\Columns\Summarizers\Summarizer::make()
                            ->label('Підсумок за фільтром')
                            ->using(function ($query) {
                                $total  = (clone $query)->sum('total_sum');
                                $paid   = (clone $query)->where('is_paid', true)->sum('total_sum');
                                $unpaid = (clone $query)->where('is_paid', false)->sum('total_sum');
                                return compact('total', 'paid', 'unpaid');
                            })
                            ->formatStateUsing(function ($state) {
                                if (!is_array($state)) return '—';
                                $fmt = fn($v) => number_format($v, 2, '.', ' ') . ' ₴';
                                return new HtmlString(
                                    "<div style='display:flex;gap:24px;align-items:center;padding:4px 0;'>"
                                    . "<span style='color:#94a3b8;font-size:12px;'>Разом: <b style='color:#e2e8f0;'>{$fmt($state['total'])}</b></span>"
                                    . "<span style='color:#94a3b8;font-size:12px;'>Оплачено: <b style='color:#22c55e;'>{$fmt($state['paid'])}</b></span>"
                                    . "<span style='color:#94a3b8;font-size:12px;'>Не оплачено: <b style='color:#f59e0b;'>{$fmt($state['unpaid'])}</b></span>"
                                    . "</div>"
                                );
                            })
                    ),

                TextColumn::make('is_paid')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'Оплачено' : 'Не оплачено')
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->filters([
                // Фільтр по діапазону дат
                Tables\Filters\Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->label('Від')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->placeholder('Початок'),
                        DatePicker::make('until')
                            ->label('До')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->placeholder('Кінець'),
                    ])
                    ->columns(2)
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'],  fn ($q, $d) => $q->whereDate('operation_date', '>=', $d))
                            ->when($data['until'], fn ($q, $d) => $q->whereDate('operation_date', '<=', $d));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if (!empty($data['from'])) {
                            $indicators[] = Tables\Filters\Indicator::make('Від ' . Carbon::parse($data['from'])->format('d.m.Y'))
                                ->removeField('from');
                        }
                        if (!empty($data['until'])) {
                            $indicators[] = Tables\Filters\Indicator::make('До ' . Carbon::parse($data['until'])->format('d.m.Y'))
                                ->removeField('until');
                        }
                        return $indicators;
                    }),

                // Фільтр по типу документу
                Tables\Filters\SelectFilter::make('type')
                    ->label('Тип документу')
                    ->options([
                        'receipt'   => 'Надходження',
                        'write_off' => 'Списання',
                    ])
                    ->placeholder('Всі типи'),

                // Фільтр по складу
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('Склад')
                    ->relationship('warehouse', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Всі склади'),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->defaultSort('operation_date', 'desc')
            ->actions([
                Tables\Actions\Action::make('toggle_paid')
                    ->label(fn ($record) => $record->is_paid ? 'Скасувати оплату' : 'Позначити оплаченим')
                    ->icon(fn ($record) => $record->is_paid ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn ($record) => $record->is_paid ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => $record->is_paid ? 'Скасувати оплату?' : 'Позначити як оплачено?')
                    ->modalDescription(fn ($record) => $record->is_paid
                        ? 'Транзакцію буде видалено з рахунку.'
                        : 'Буде створена транзакція та списано кошти з рахунку.')
                    ->action(function ($record) {
                        // Беремо свіжий стан з БД щоб уникнути кешованих даних Livewire
                        $fresh = $record->fresh();
                        $fresh->update(['is_paid' => !$fresh->is_paid]);
                    }),
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
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