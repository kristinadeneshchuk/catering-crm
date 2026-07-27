<?php

namespace App\Filament\Resources;

use App\Traits\AllowCookAccess;

use App\Filament\Resources\StockDocumentResource\Pages;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

use Filament\Tables\Columns\TextColumn;

class StockDocumentResource extends Resource
{
    use AllowCookAccess;
    protected static ?string $model = StockDocument::class;
    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Документи складу';
    protected static ?string $breadcrumb = 'Документи складу';
    protected static ?string $pluralModelLabel = 'Документи складу';
    protected static ?string $modelLabel = 'Документ складу';

    /**
     * Перерахунок упаковочного рядка у величини, з якими працює приход:
     *   input_qty  — загальна вага/об'єм у БАЗОВІЙ одиниці товару (кг/л/шт),
     *   total_price— сума (к-сть × ціна за упаковку),
     *   unit_price — ціна за базову одиницю (для показу),
     *   input_unit — базова одиниця (далі модель нормалізує qty = input_qty × 1).
     */
    protected static function recalcPackRow(Forms\Get $get, Forms\Set $set): void
    {
        $base  = $get('base_unit') ?: 'кг';
        $pkgW  = (float) ($get('package_weight') ?? 0);
        $pkgU  = $get('package_unit') ?: $base;
        $count = (float) ($get('pack_count') ?? 0);
        $price = (float) ($get('pack_price') ?? 0);

        $factor  = StockDocumentItem::unitFactor($pkgU, $base); // напр. г→кг = 0.001
        $baseQty = round($count * $pkgW * $factor, 3);
        $total   = round($count * $price, 2);

        $set('input_unit', $base);
        $set('input_qty', $baseQty);
        $set('total_price', $total);
        $set('unit_price', $baseQty > 0 ? round($total / $baseQty, 4) : 0);
    }

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
                            // При редагуванні відновлюємо рядок у «накладному» вигляді:
                            // одиниця + кількість як їх вводили, ціна за цю одиницю.
                            ->mutateRelationshipDataBeforeFillUsing(function (array $data): array {
                                $baseUnit = 'кг';
                                if (!empty($data['itemable_type']) && !empty($data['itemable_id'])
                                    && class_exists($data['itemable_type'])) {
                                    $baseUnit = StockDocumentItem::canonUnit(
                                        $data['itemable_type']::find($data['itemable_id'])?->unit
                                    ) ?: 'кг';
                                }

                                $inputUnit = StockDocumentItem::canonUnit($data['input_unit'] ?? '') ?: $baseUnit;
                                $inputQty  = ($data['input_qty'] ?? null) !== null
                                    ? (float) $data['input_qty']
                                    : (float) ($data['qty'] ?? 0);
                                $total     = (float) ($data['total_price'] ?? 0);

                                $data['base_unit']  = $baseUnit;
                                $data['input_unit'] = $inputUnit;
                                $data['input_qty']  = $inputQty;
                                $data['unit_price'] = $inputQty > 0 ? round($total / $inputQty, 4) : (float) ($data['price'] ?? 0);

                                // 📦 Відновлюємо «упаковочний» вигляд: вага упаковки — з
                                // картки товару, к-сть/ціна упаковки — зі снапшоту рядка.
                                $ing = (!empty($data['itemable_type']) && !empty($data['itemable_id'])
                                    && $data['itemable_type'] === Ingredient::class)
                                    ? Ingredient::find($data['itemable_id'])
                                    : null;

                                $isPackaged = $ing && $ing->is_packaged && ($data['pack_count'] ?? null) !== null;
                                $data['is_packaged']    = $isPackaged;
                                $data['package_weight'] = $isPackaged ? (float) $ing->package_weight : null;
                                $data['package_unit']   = $isPackaged ? ($ing->package_unit ?: $baseUnit) : null;

                                return $data;
                            })
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

                                // Базова одиниця товару (г/кг/мл/л/шт) — для конвертації
                                // введеної одиниці та фільтрації сумісних опцій. У БД не пишемо.
                                Hidden::make('base_unit')
                                    ->default('кг')
                                    ->dehydrated(false),

                                // 📦 Упаковочний режим рядка (форма-онлі, у БД не пишемо —
                                // вагу упаковки беремо з картки, снапшот тримаємо в pack_*).
                                Hidden::make('is_packaged')
                                    ->default(false)
                                    ->dehydrated(false),
                                Hidden::make('package_unit')
                                    ->dehydrated(false),

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
                                    // Серверний пошук замість завантаження всієї таблиці.
                                    // Раніше options() тягнув повний Ingredient/Packaging для КОЖНОГО
                                    // рядка репітера при кожному live-рендері → 508 Resource Limit.
                                    ->getSearchResultsUsing(function (string $search, Forms\Get $get) {
                                        $category = $get('../../item_category');
                                        if (!$category) return [];

                                        $modelClass = $category === 'packaging'
                                            ? Packaging::class
                                            : Ingredient::class;

                                        return $modelClass::query()
                                            ->where('name', 'like', "%{$search}%")
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->pluck('name', 'id')
                                            ->mapWithKeys(fn ($name, $id) => [
                                                $modelClass . '||' . $id => $name,
                                            ])
                                            ->all();
                                    })
                                    ->getOptionLabelUsing(function ($value) {
                                        if (!$value || !str_contains($value, '||')) return null;
                                        [$modelClass, $modelId] = explode('||', $value);
                                        if (!class_exists($modelClass)) return null;
                                        return $modelClass::find($modelId)?->name;
                                    })
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        if (!$state) {
                                            $set('itemable_type', null);
                                            $set('itemable_id', null);
                                            $set('base_unit', 'кг');
                                            $set('unit_price', null);
                                            $set('total_price', 0);
                                            $set('is_packaged', false);
                                            $set('package_weight', null);
                                            $set('package_unit', null);
                                            $set('pack_count', null);
                                            $set('pack_price', null);
                                            return;
                                        }

                                        // Розбиваємо наш ключ на Клас та ID
                                        [$modelClass, $modelId] = explode('||', $state);

                                        // Записуємо в приховані поля
                                        $set('itemable_type', $modelClass);
                                        $set('itemable_id', $modelId);

                                        $item = $modelClass::find($modelId);
                                        if (!$item) return;

                                        // За замовчуванням одиниця вводу = власна одиниця товару
                                        $baseUnit = StockDocumentItem::canonUnit($item->unit) ?: 'кг';
                                        $set('base_unit', $baseUnit);
                                        $set('input_unit', $baseUnit);
                                        $set('price_manual', false);

                                        // 📦 Упаковочний товар (тільки надходження): закупник
                                        // вводить к-сть упаковок і ціну за упаковку.
                                        $isPackaged = $item instanceof Ingredient
                                            && $item->is_packaged
                                            && $get('../../type') === 'receipt';
                                        $set('is_packaged', $isPackaged);
                                        $set('package_weight', $isPackaged ? (float) $item->package_weight : null);
                                        $set('package_unit', $isPackaged ? ($item->package_unit ?: $baseUnit) : null);

                                        if ($isPackaged) {
                                            $set('pack_count', 1);
                                            $set('pack_price', null);
                                            $set('input_unit', $baseUnit);
                                            $set('input_qty', round((float) $item->packageBaseWeight(), 3));
                                            $set('unit_price', null);
                                            $set('total_price', 0);
                                            return;
                                        }

                                        $set('pack_count', null);
                                        $set('pack_price', null);

                                        if ($get('../../type') === 'receipt') {
                                            // Надходження: ціну вводять з накладної вручну
                                            $set('unit_price', null);
                                            $set('total_price', 0);
                                        } else {
                                            // Списання: підставляємо відому середню ціну (за базову одиницю).
                                            $defaultPrice = $item instanceof Ingredient
                                                ? (float) ($item->average_price ?? 0)
                                                : (float) ($item->price ?? 0);
                                            $defaultPrice = round($defaultPrice, 4);
                                            $set('unit_price', $defaultPrice);
                                            $qty = (float) ($get('input_qty') ?? 0);
                                            $set('total_price', round($qty * $defaultPrice, 2));
                                        }
                                    }),

                                // 📦 Поля упаковочного режиму (тільки коли товар «продається упаковками»)
                                TextInput::make('package_weight')
                                    ->label('📦 Вага упаковки')
                                    ->numeric()
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->suffix(fn (Forms\Get $get) => $get('package_unit') ?: '')
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->columnSpan(1),

                                TextInput::make('pack_count')
                                    ->label('К-сть упаковок')
                                    ->numeric()
                                    ->default(1)
                                    ->live(onBlur: true)
                                    // Знімок пишемо лише для упаковочних рядків
                                    ->dehydrated(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::recalcPackRow($get, $set))
                                    ->columnSpan(1),

                                TextInput::make('pack_price')
                                    ->label('Ціна за упаковку')
                                    ->numeric()
                                    ->prefix('₴')
                                    ->live(onBlur: true)
                                    ->dehydrated(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->afterStateUpdated(fn (Forms\Get $get, Forms\Set $set) => static::recalcPackRow($get, $set))
                                    ->columnSpan(1),

                                // Одиниця, у якій вводимо позицію (як на накладній).
                                // Опції обмежені сумісними з базовою одиницею товару.
                                // У упаковочному режимі приховано (одиниця = базова).
                                Select::make('input_unit')
                                    ->label('Од.')
                                    ->options(fn (Forms\Get $get) => collect(
                                            StockDocumentItem::compatibleUnits($get('base_unit'))
                                        )->mapWithKeys(fn ($u) => [$u => $u])->all())
                                    ->default('кг')
                                    ->required()
                                    ->live()
                                    ->dehydrated(true)
                                    ->visible(fn (Forms\Get $get) => !$get('is_packaged'))
                                    ->columnSpan(1),

                                TextInput::make('input_qty')
                                    ->label(fn (Forms\Get $get) => $get('is_packaged') ? 'Загальна вага' : 'Кількість')
                                    ->numeric()
                                    ->default(1)
                                    ->required()
                                    ->live(onBlur: true)
                                    ->dehydrated(true)
                                    // У упаковочному режимі рахується з к-сті упаковок
                                    ->readOnly(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->suffix(fn (Forms\Get $get) => $get('is_packaged') ? ($get('base_unit') ?: '') : null)
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $price = (float) ($get('unit_price') ?? 0);
                                        $set('total_price', round((float) $state * $price, 2));
                                    })
                                    ->columnSpan(1),

                                TextInput::make('unit_price')
                                    ->label(fn (Forms\Get $get) => 'Ціна за 1 ' . (StockDocumentItem::canonUnit($get('input_unit')) ?: 'од.'))
                                    ->numeric()
                                    ->prefix('₴')
                                    ->required()
                                    // У упаковочному режимі — рахується (сума / загальна вага)
                                    ->readOnly(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->live(onBlur: true)
                                    ->dehydrated(false) // віртуальне: у БД пишемо нормалізовану price
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $set('price_manual', true);
                                        $qty = (float) ($get('input_qty') ?? 0);
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

                                        // Середня ціна зберігається за БАЗОВУ одиницю —
                                        // приводимо до обраної одиниці вводу для чесного порівняння.
                                        $lastBase = $item instanceof Ingredient
                                            ? (float) ($item->average_price ?? 0)
                                            : (float) ($item->price ?? 0);
                                        $factor   = StockDocumentItem::unitFactor($get('input_unit'), $item->unit);
                                        $lastPrice = $lastBase * $factor;
                                        $unitLabel = StockDocumentItem::canonUnit($get('input_unit')) ?: 'од.';

                                        if ($lastPrice <= 0) {
                                            return new HtmlString('<span class="text-[11px] text-gray-500">Перша закупівля (немає історії цін)</span>');
                                        }

                                        $currentPrice = (float) $get('unit_price');
                                        $message = "<span class='text-[11px] text-gray-500'>Попередня ціна: <b>" . number_format($lastPrice, 2) . " ₴/{$unitLabel}</b></span>";

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
                                    })
                                    ->columnSpan(1),

                                TextInput::make('total_price')
                                    ->label('Сума')
                                    ->numeric()
                                    ->prefix('₴')
                                    ->live(onBlur: true)
                                    ->dehydrated(true)
                                    // У упаковочному режимі сума = к-сть × ціна за упаковку
                                    ->readOnly(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->helperText(fn (Forms\Get $get) => $get('is_packaged')
                                        ? null
                                        : 'Можна ввести суму — ціна за одиницю дорахується')
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $qty = (float) ($get('input_qty') ?? 0);
                                        if ($qty > 0) {
                                            $set('unit_price', round((float) $state / $qty, 4));
                                        }
                                    })
                                    ->columnSpan(1),
                            ])
                            ->columns(6),

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

                // Фільтр по постачальнику
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Постачальник')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('Усі'),

                // Пошук накладних за назвою товару (інгредієнт або тара)
                Tables\Filters\Filter::make('product_search')
                    ->form([
                        TextInput::make('product')
                            ->label('Пошук товару')
                            ->placeholder('Напр.: яблуко')
                            ->autocomplete(false),
                    ])
                    ->query(function ($query, array $data) {
                        $term = trim((string) ($data['product'] ?? ''));
                        if ($term === '') return $query;
                        $like = '%' . $term . '%';

                        return $query->whereExists(function ($sub) use ($like) {
                            $sub->select(DB::raw(1))
                                ->from('stock_document_items as sdi')
                                ->whereColumn('sdi.stock_document_id', 'stock_documents.id')
                                ->where(function ($w) use ($like) {
                                    $w->whereExists(function ($q) use ($like) {
                                        $q->select(DB::raw(1))
                                            ->from('ingredients')
                                            ->whereColumn('ingredients.id', 'sdi.itemable_id')
                                            ->where('sdi.itemable_type', \App\Models\Ingredient::class)
                                            ->where('ingredients.name', 'like', $like);
                                    })->orWhereExists(function ($q) use ($like) {
                                        $q->select(DB::raw(1))
                                            ->from('packagings')
                                            ->whereColumn('packagings.id', 'sdi.itemable_id')
                                            ->where('sdi.itemable_type', \App\Models\Packaging::class)
                                            ->where('packagings.name', 'like', $like);
                                    });
                                });
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        $term = trim((string) ($data['product'] ?? ''));
                        if ($term === '') return [];
                        return [
                            Tables\Filters\Indicator::make("Товар: {$term}")->removeField('product'),
                        ];
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(6)
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