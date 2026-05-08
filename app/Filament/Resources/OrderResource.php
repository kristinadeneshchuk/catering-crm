<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\DeliveryCalendarRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\ActivityLogsRelationManager;
use App\Models\Order;
use App\Models\Client;
use App\Models\Tariff;
use App\Models\CalorieRange;
use App\Models\TariffPrice;
use App\Services\ScheduleService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\ViewField;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Carbon\Carbon;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Order::class;
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Замовлення';
    protected static ?string $pluralModelLabel = 'Замовлення';
    protected static ?string $modelLabel = 'Замовлення';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // === СЕКЦІЯ 1: Основні дані ===
                Section::make('Основна інформація')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->relationship('client', 'name')
                            ->searchable()
                            ->required()
                            ->preload()
                            ->live()
                            ->label('Клієнт')
                            ->default(fn () => request('client_id'))
                            ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->name} (ID: {$record->id})")
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $client = Client::find($state);
                                if ($client) {
                                    $set('calories', $client->target_kcal);
                                    static::updateOrderTotals($set, $get);
                                }
                            }),

                        TextInput::make('total_price')
                            ->label('Сума замовлення')
                            ->prefix('₴')
                            ->numeric()
                            ->default(0)
                            ->required()
                            ->readOnly()
                            ->dehydrated() 
                            ->helperText('Розраховується автоматично'),

                        Select::make('tariff_id')
                            ->label('Тариф')
                            ->relationship('tariff', 'name', fn (Builder $query) => $query->where('is_active', true))
                            // 🔥 Динамічна назва проєкту з бази (або резервна зі старого поля)
                            ->getOptionLabelFromRecordUsing(fn ($record) =>
                                "{$record->name} (" . ($record->projectData?->name ?? $record->project) . ")"
                            )
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $tariff = Tariff::find($state);
                                if ($tariff) {
                                    // Оновлюємо проєкт при зміні тарифу
                                    $set('project', $tariff->project);
                                    // Підставляємо план меню з тарифу (перезаписує системний дефолт);
                                    // якщо у тарифа план не вказано — лишаємо поточне значення.
                                    if (!empty($tariff->default_menu_plan_id)) {
                                        $set('menu_plan_id', $tariff->default_menu_plan_id);
                                    }
                                    static::updateOrderTotals($set, $get);
                                }
                            })
                            ->searchable()
                            ->preload(),

                        TextInput::make('calories')
                            ->numeric()
                            ->required()
                            ->label('Калорії (Ккал)')
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::updateOrderTotals($set, $get))
                            ->helperText(function (Get $get) {
                                $cal = (int) $get('calories');
                                $tariffId = $get('tariff_id');
                                if (!$cal) return null;

                                $range = \App\Models\CalorieRange::where('min_kcal', '<=', $cal)
                                    ->where('max_kcal', '>=', $cal)->first();
                                if (!$range) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<span style="color:#ef4444;font-weight:700;">⚠️ Жоден діапазон калорій не охоплює '.$cal.' ккал — ціна буде 0₴. Створіть/розширте діапазон у «Діапазони калорій».</span>'
                                    );
                                }
                                if (!$tariffId) return "Діапазон: {$range->name} ({$range->min_kcal}–{$range->max_kcal} ккал)";

                                $price = \App\Models\TariffPrice::where('tariff_id', $tariffId)
                                    ->where('calorie_range_id', $range->id)->value('price_per_day');
                                if ($price === null) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<span style="color:#ef4444;font-weight:700;">⚠️ Для цього тарифу немає ціни на діапазон «'.$range->name.'» — ціна буде 0₴. Заповніть у «Категорії тарифів».</span>'
                                    );
                                }
                                if ((float)$price <= 0) {
                                    return new \Illuminate\Support\HtmlString(
                                        '<span style="color:#f59e0b;font-weight:700;">⚠️ Ціна для діапазону «'.$range->name.'» = 0₴. Перевір налаштування тарифу.</span>'
                                    );
                                }
                                return new \Illuminate\Support\HtmlString(
                                    '<span style="color:#10b981;">Діапазон: <b>'.$range->name.'</b> · Ціна: <b>'.number_format((float)$price, 0, '.', ' ').' ₴/день</b></span>'
                                );
                            }),

                        Hidden::make('status')
                            ->default('new'),

                        // 🔥 Оновлено на новий системний slug
                        Hidden::make('project')
                            ->default(null),
                    ]),

                // === СЕКЦІЯ 2: Дати та Логістика ===
                Section::make('Період та Логістика')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('start_date')
                            ->required()
                            ->label('Дата початку')
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::updateOrderTotals($set, $get)),

                        TextInput::make('duration')
                            ->label('Кількість днів')
                            ->numeric()
                            ->default(1)
                            ->minValue(1)
                            ->required()
                            ->live() 
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::updateOrderTotals($set, $get)),

                        DatePicker::make('end_date')
                            ->label('Дата закінчення (орієнтовно)')
                            ->readOnly()
                            ->live(), 

                        Select::make('menu_type')
                            ->label('Тип меню')
                            ->options([
                                'cyclic'     => 'Циклічне (стандарт)',
                                'individual' => 'Персональне (індивідуальник)',
                            ])
                            ->default('cyclic')
                            ->required()
                            ->live()
                            ->columnSpan(1),

                        Select::make('menu_plan_id')
                            ->label('План меню')
                            ->relationship('menuPlan', 'name')
                            ->options(fn () => \App\Models\MenuPlan::orderBy('sort_order')->orderBy('id')->pluck('name', 'id'))
                            ->default(fn () => optional(\App\Models\MenuPlan::default())->id)
                            ->visible(fn (Get $get) => $get('menu_type') !== 'individual')
                            ->required(fn (Get $get) => $get('menu_type') !== 'individual')
                            ->preload()
                            ->searchable()
                            ->columnSpan(1),

                        Select::make('schedule_type')
                            ->label('Графік доставки')
                            ->options(ScheduleService::getScheduleTypes())
                            ->required()
                            ->columnSpan(2)
                            ->live()
                            ->afterStateUpdated(function ($state, Set $set, \Livewire\Component $livewire) {
                                $set('delivery_time', null);
                                $livewire->dispatch('update-schedule-type', type: $state);
                            }),

                        Select::make('delivery_time')
                            ->label('Час доставки')
                            ->options(fn (Get $get) => ScheduleService::getTimeSlots($get('schedule_type')))
                            ->required()
                            ->disabled(fn (Get $get) => empty($get('schedule_type'))),

                        Textarea::make('comment')
                            ->label('Коментар / Адреса доставки')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),

                // === СЕКЦІЯ 3: ЗНИЖКА ===
                Section::make('Знижка')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        Select::make('discount_type')
                            ->label('Тип знижки')
                            ->options([
                                'percent' => 'Відсоткова (%)',
                                'fixed'   => 'Фіксована (₴)',
                            ])
                            ->nullable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, Get $get) {
                                $set('discount_value', null);
                                $set('discount_amount', 0);
                                static::updateOrderTotals($set, $get);
                            }),

                        TextInput::make('discount_value')
                            ->label(fn (Get $get) => $get('discount_type') === 'percent' ? 'Розмір знижки (%)' : 'Розмір знижки (₴)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(fn (Get $get) => $get('discount_type') === 'percent' ? 100 : null)
                            ->suffix(fn (Get $get) => $get('discount_type') === 'percent' ? '%' : '₴')
                            ->visible(fn (Get $get) => filled($get('discount_type')))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::updateOrderTotals($set, $get)),

                        Textarea::make('discount_reason')
                            ->label('Причина знижки')
                            ->rows(2)
                            ->columnSpanFull(),

                        TextInput::make('discount_amount')
                            ->label('Сума знижки')
                            ->prefix('₴')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated()
                            ->helperText('Розраховується автоматично'),

                        TextInput::make('final_price')
                            ->label('До сплати (з урахуванням знижок)')
                            ->prefix('₴')
                            ->numeric()
                            ->default(0)
                            ->readOnly()
                            ->dehydrated()
                            ->helperText('total_price − всі знижки'),
                    ]),

                // === СЕКЦІЯ 4: ДОДАТКОВІ РАЦІОНИ (сімейні замовлення) ===
                Section::make('Додаткові раціони')
                    ->description('Для сімейних замовлень — той самий клієнт, та сама адреса, але різні раціони (чоловік/дружина тощо). Кожен раціон стає окремим замовленням із тими ж датами.')
                    ->collapsed()
                    ->schema([
                        Repeater::make('additional_rations')
                            ->label('')
                            ->addActionLabel('+ Додати раціон')
                            ->defaultItems(0)
                            ->collapsible()
                            ->itemLabel(fn (array $state): string =>
                                collect([
                                    ($state['status'] ?? 'active') === 'paused' ? '⏸' : '▶',
                                    $state['tariff_id'] ? (Tariff::find($state['tariff_id'])?->name ?? 'Тариф') : 'Новий раціон',
                                    $state['calories'] ? $state['calories'] . ' ккал' : null,
                                    isset($state['price_per_day']) && $state['price_per_day'] > 0
                                        ? '— ' . number_format($state['price_per_day'], 0, '.', ' ') . ' ₴/день'
                                        : null,
                                    ($state['status'] ?? 'active') === 'paused' ? '(на паузі)' : null,
                                ])->filter()->join(' ')
                            )
                            ->schema([
                                // ID дочірнього замовлення (для редагування)
                                Hidden::make('order_id'),

                                Select::make('tariff_id')
                                    ->label('Тариф')
                                    ->options(fn () => Tariff::where('is_active', true)
                                        ->get()
                                        ->mapWithKeys(fn ($t) => [
                                            $t->id => $t->name . ' (' . ($t->projectData?->name ?? $t->project) . ')'
                                        ]))
                                    ->required()
                                    ->live()
                                    ->searchable()
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $tariff = Tariff::find($state);
                                        if ($tariff) {
                                            $set('project', $tariff->project);
                                            if (!empty($tariff->default_menu_plan_id)) {
                                                $set('menu_plan_id', $tariff->default_menu_plan_id);
                                            }
                                        }
                                        static::updateRationPrice($state, (int) $get('calories'), $set);
                                    }),

                                TextInput::make('calories')
                                    ->label('Калорії (Ккал)')
                                    ->numeric()
                                    ->required()
                                    ->minValue(500)
                                    ->maxValue(5000)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn ($state, Set $set, Get $get) =>
                                        static::updateRationPrice($get('tariff_id'), (int) $state, $set)
                                    ),

                                Select::make('menu_type')
                                    ->label('Тип меню')
                                    ->options([
                                        'cyclic'     => 'Циклічне (стандарт)',
                                        'individual' => 'Персональне (індивідуальник)',
                                    ])
                                    ->default('cyclic')
                                    ->live()
                                    ->required(),

                                Select::make('menu_plan_id')
                                    ->label('План меню')
                                    ->options(fn () => \App\Models\MenuPlan::orderBy('sort_order')->orderBy('id')->pluck('name', 'id'))
                                    ->default(fn () => optional(\App\Models\MenuPlan::default())->id)
                                    ->visible(fn (Get $get) => $get('menu_type') !== 'individual')
                                    ->required(fn (Get $get) => $get('menu_type') !== 'individual')
                                    ->preload()
                                    ->searchable(),

                                TextInput::make('price_per_day')
                                    ->label('Ціна / день')
                                    ->prefix('₴')
                                    ->numeric()
                                    ->default(0)
                                    ->readOnly()
                                    ->dehydrated(false)
                                    ->helperText('Розраховується автоматично'),

                                Select::make('status')
                                    ->label('Статус раціону')
                                    ->options([
                                        'active' => '▶ Активний',
                                        'paused' => '⏸ На паузі',
                                    ])
                                    ->default('active')
                                    ->required()
                                    ->helperText('На паузі — не їде в логістику і не фасується'),

                                Hidden::make('project'),
                            ])
                            ->columns(5)
                            ->dehydrated(false),
                    ]),

                // === СЕКЦІЯ 5: КАЛЕНДАР ===
                Section::make('Календар харчування')
                    ->schema([
                        TextInput::make('selected_days_buffer')
                            ->view('filament.forms.components.order-calendar-field')
                            ->default('[]')
                            ->live()
                            ->dehydrated(true)
                            ->label(''),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable()->searchable(),

                // 🔥 Динамічна колонка проєкту (тягне назву і колір з БД)
                TextColumn::make('projectData.name')
                    ->label('Проєкт')
                    ->badge()
                    ->color(fn ($record): string => $record->projectData?->color ?? 'gray')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Клієнт')
                    ->description(fn ($record) => collect(array_filter([
                        "ID: {$record->client_id}",
                        $record->parent_order_id ? "📦 Раціон до #{$record->parent_order_id}" : null,
                    ]))->join(' · '))
                    ->searchable(['clients.name', 'orders.client_id'])
                    ->sortable(),


                TextColumn::make('current_day')
                    ->label('День')
                    ->getStateUsing(function ($record) {
                        $allDays = $record->orderDays()
                            ->orderBy('date', 'asc')
                            ->pluck('date')
                            ->map(fn($date) => Carbon::parse($date)->format('Y-m-d'))
                            ->toArray();

                        $total = count($allDays);
                        if ($total === 0) return null;

                        $today = Carbon::now()->format('Y-m-d');
                        $currentDayNumber = 0;

                        foreach ($allDays as $index => $date) {
                            if ($date <= $today) {
                                $currentDayNumber = $index + 1;
                            }
                        }

                        // Якщо рацион закінчився (всі дні пройшли і останній день не сьогодні) — не показуємо лічильник
                        if ($currentDayNumber === $total && end($allDays) < $today) return null;

                        // Якщо жоден день ще не почався
                        if ($currentDayNumber === 0) return "0 / {$total}";

                        return "{$currentDayNumber} / {$total}";
                    })
                    ->badge()
                    ->color(fn ($state) => str_starts_with($state, '0 /') ? 'gray' : 'primary'),

                IconColumn::make('is_paid')
                    ->label('Оплата')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger')
                    ->alignCenter(),

                TextColumn::make('final_price')
                    ->label('До сплати')
                    ->money('UAH')
                    ->description(function ($record) {
                        $totalDiscount = (float) $record->total_price - (float) $record->final_price;
                        return $totalDiscount > 0
                            ? '−₴' . number_format($totalDiscount, 2) . ' знижка'
                            : null;
                    })
                    ->sortable(),

                TextColumn::make('duration')
                    ->label('Днів')
                    ->alignCenter(),

                TextColumn::make('start_date')
                    ->label('Початок')
                    ->date()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'new' => 'gray',
                        'active' => 'success',
                        'paused' => 'warning',
                        'completed', 'finished' => 'info',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'new' => 'Новий',
                        'active' => 'Активний',
                        'paused' => 'На паузі',
                        'completed', 'finished' => 'Завершений',
                        default => $state,
                    })
                    ->sortable(query: function (Builder $query, string $direction) {
                        return $query
                            ->orderByRaw("
                                CASE 
                                    WHEN status = 'new' THEN 1 
                                    WHEN status = 'active' THEN 2 
                                    WHEN status = 'paused' THEN 3 
                                    WHEN status = 'completed' THEN 4
                                    WHEN status = 'finished' THEN 4
                                    ELSE 5 
                                END $direction
                            ")
                            ->orderBy('id', 'desc'); // Вторинне сортування (щоб новіші замовлення з однаковим статусом були вище)
                    }),
            ])
            ->defaultSort('status', 'asc')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),

                // Призупинити — прибирає з логістики, виробничого і фасовочного
                Tables\Actions\Action::make('pause')
                    ->label('')
                    ->tooltip('Призупинити замовлення')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn ($record) => in_array($record->status, ['new', 'active']))
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Призупинити замовлення #{$record->id}?")
                    ->modalDescription(fn ($record) =>
                        "Клієнт: {$record->client->name}\n" .
                        "Раціон: " . ($record->projectData?->name ?? $record->project) . ", {$record->calories} ккал\n\n" .
                        "Замовлення зникне з логістики, виробничого та фасовочного."
                    )
                    ->modalSubmitActionLabel('Призупинити')
                    ->action(fn ($record) => $record->update(['status' => 'paused'])),

                // Відновити — повертає замовлення у роботу
                Tables\Actions\Action::make('resume')
                    ->label('')
                    ->tooltip('Відновити замовлення')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'paused')
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => "Відновити замовлення #{$record->id}?")
                    ->modalDescription(fn ($record) =>
                        "Клієнт: {$record->client->name}\n" .
                        "Раціон: " . ($record->projectData?->name ?? $record->project) . ", {$record->calories} ккал\n\n" .
                        "Замовлення знову з'явиться в логістиці, виробничому та фасовочному."
                    )
                    ->modalSubmitActionLabel('Відновити')
                    ->action(fn ($record) => $record->update(['status' => 'active'])),

                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
            ActivityLogsRelationManager::class,
        ];
    }

    /**
     * Розраховує ціну/день для одного додаткового раціону і встановлює її у поле price_per_day.
     */
    protected static function updateRationPrice($tariffId, int $calories, Set $set): void
    {
        $pricePerDay = 0;

        if ($tariffId && $calories > 0) {
            $range = CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();

            if ($range) {
                $entry = TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();

                if ($entry) {
                    $pricePerDay = (float) $entry->price_per_day;
                }
            }
        }

        $set('price_per_day', $pricePerDay);
    }

    protected static function updateOrderTotals(Set $set, Get $get)
    {
        $calories      = (int) $get('calories');
        $tariffId      = $get('tariff_id');
        $duration      = (int) $get('duration') ?: 1;
        $discountType  = $get('discount_type');
        $discountValue = (float) $get('discount_value');

        $totalPrice = 0;

        if ($calories && $tariffId) {
            $range = CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();

            if ($range) {
                $priceEntry = TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();

                if ($priceEntry) {
                    $totalPrice = $priceEntry->price_per_day * $duration;
                }
            }
        }

        $set('total_price', $totalPrice);

        // Рахуємо знижку рівня замовлення
        $discountAmount = match ($discountType) {
            'percent' => $discountValue > 0 ? round($totalPrice * $discountValue / 100, 2) : 0,
            'fixed'   => $discountValue > 0 ? min($discountValue, $totalPrice) : 0,
            default   => 0,
        };

        $set('discount_amount', $discountAmount);
        $set('final_price', max(0, $totalPrice - $discountAmount));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}