<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\AddressesRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\ActivityLogsRelationManager;
use App\Models\Client;
use App\Models\MealType;
use App\Models\MealPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\CheckboxList;
use Carbon\Carbon;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Set;

class ClientResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Client::class;
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationLabel = 'Клієнти';
    protected static ?string $pluralModelLabel = 'Клієнти';
    protected static ?string $modelLabel = 'Клієнт';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // === СЕКЦІЯ: ФІНАНСИ ===
                Section::make('Фінанси')
                    ->schema([
                        TextInput::make('balance')
                            ->label('Поточний баланс')
                            ->default(0)
                            ->numeric()
                            ->prefix('₴')
                            ->readOnly()
                            ->extraInputAttributes(fn ($state) => [
                                'style' => 'font-weight: bold; font-size: 1.5rem; color: ' . ($state < 0 ? '#dc2626' : '#16a34a'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Основна інформація')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->label("Ім'я")
                            ->extraInputAttributes(['autocomplete' => 'off'])
                            ->datalist(
                                Client::latest()->limit(5)->pluck('name')->toArray()
                            ),

                        TextInput::make('phone')
                            ->tel()
                            ->required()
                            ->label('Телефон')
                            ->extraInputAttributes(['autocomplete' => 'off']),

                        TextInput::make('email')
                            ->email()
                            ->label('Email'),

                        Select::make('sales_source')
                            ->label('Джерело продаж')
                            ->options([
                                'Залишив заявку РК' => 'Залишив заявку РК',
                                'Залишив заявку СЕО' => 'Залишив заявку СЕО',
                                'Повідомлення в соц мережі з реклами' => 'Повідомлення в соц мережі з реклами',
                                'Написав в телеграм' => 'Написав в телеграм',
                                'Написав в вайбер' => 'Написав в вайбер',
                                'Телефонний дзвінок' => 'Телефонний дзвінок',
                                'Рекомендація' => 'Рекомендація',
                            ])
                            ->searchable(),

                        \Filament\Forms\Components\Placeholder::make('cabinet_link')
                            ->label('Кабінет клієнта (надішли клієнту цей лінк / QR)')
                            ->visible(fn ($record) => $record && $record->cabinet_token)
                            ->content(fn ($record) => new \Illuminate\Support\HtmlString(
                                '<a href="' . url('/cabinet/' . $record->cabinet_token) . '" target="_blank" style="color:#d97706;text-decoration:underline;word-break:break-all;">'
                                . url('/cabinet/' . $record->cabinet_token) . '</a>'
                            ))
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Налаштування раціону')
                    ->description('Введіть калораж, і система автоматично підбере кількість страв.')
                    ->schema([
                        TextInput::make('target_kcal')
                            ->label('Цільові калорії')
                            ->numeric()
                            ->default(0)
                            ->suffix('ккал')
                            ->live(onBlur: true) 
                            ->afterStateUpdated(function ($state, Set $set) {
                                if (!$state) return;

                                // Тягнемо логіку з централізованих "Планів харчування"
                                $mealTypeIds = MealPlan::getAllowedMealTypeIds((int) $state);
                                $set('mealTypes', $mealTypeIds);
                            }),

                        Toggle::make('has_cutlery')
                            ->label('Чи додавати прибори?')
                            ->helperText('Якщо увімкнено, до замовлення будуть додані одноразові прибори')
                            ->default(true)
                            ->inline(false)
                            ->onIcon('heroicon-m-check')
                            ->offIcon('heroicon-m-x-mark')
                            ->onColor('success'),

                        Select::make('water_option')
                            ->label('Вода')
                            ->options([
                                'with_water'         => 'З водою',
                                'without_water'      => 'Без води',
                                'water_without_lemon' => 'Вода без лимону',
                            ])
                            ->default('with_water'),

                        CheckboxList::make('mealTypes')
                            ->label('Активні прийоми їжі')
                            ->relationship('mealTypes', 'name')
                            ->bulkToggleable()
                            ->columns(3)
                            ->gridDirection('row')
                            ->default(fn () => MealType::pluck('id')->toArray())
                            ->required(),
                    ]),

                Section::make('Персоналізація меню')
                    ->icon('heroicon-o-no-symbol')
                    ->schema([
                        Select::make('replacementBundles')
                            ->label('Шаблони замін')
                            ->helperText('Усі інгредієнти з шаблону автоматично вважаються виключеннями (наприклад «Без м\'яса», «Безлактозний»).')
                            ->relationship('replacementBundles', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),

                        Select::make('ingredientExclusions')
                            ->label('Продукти виключення')
                            ->relationship('ingredientExclusions', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),

                        Select::make('dishExclusions')
                            ->label('Блюда виключення')
                            ->relationship('dishExclusions', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload(),

                        Textarea::make('production_comment')
                            ->label('Коментар для виробництва')
                            ->columnSpanFull(),
                    ])->columns(2),

                // Бриф іде в ШІ, який складає персональне меню. Навмисно одне
                // поле, а не десяток: анкети в клієнтів різні, і будь-яка
                // структура їх обрізатиме.
                Section::make('Бриф для індивідуального меню')
                    ->icon('heroicon-o-sparkles')
                    ->description('Вставте анкету клієнта одним шматком: вік, вага, ціль, активність, кількість прийомів, що не їсть, що любить. Звідси ШІ складає персональне меню на сторінці «Персональні меню».')
                    ->collapsed(fn ($record) => empty($record?->menu_brief))
                    ->schema([
                        Textarea::make('menu_brief')
                            ->label('')
                            ->placeholder("1. Мої дані:\n• Вік: 29 років\n• Зріст: 153 см\n…\n\n4. Продукти та страви, які НЕ їм:\n• кефір\n• буряк\n…\n\n5. Що дуже люблю:\n• том ям\n• куряче філе\n…")
                            ->rows(14)
                            ->columnSpanFull(),
                    ]),
                
                Section::make('Зв\'язок у соцмережах')
                    ->collapsed()
                    ->schema([
                        TextInput::make('instagram_url')
                            ->label('Instagram')
                            ->placeholder('https://instagram.com/...')
                            ->url()
                            ->prefixIcon('heroicon-o-camera'),

                        TextInput::make('telegram_username')
                            ->label('Telegram')
                            ->placeholder('username')
                            ->prefix('t.me/')
                            ->prefixIcon('heroicon-o-paper-airplane'),

                        TextInput::make('facebook_url')
                            ->label('Facebook')
                            ->url()
                            ->prefixIcon('heroicon-o-user-circle'),
                    ])->columns(3),

                Section::make('Для менеджера')
                    ->schema([
                        Textarea::make('manager_comment')
                            ->label('Коментар менеджера')
                            ->placeholder('Наприклад: Передзвонити клієнту')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),

                // Тільки при створенні — адреси через Repeater (при редагуванні є вкладка)
                Section::make('Адреси доставки')
                    ->icon('heroicon-o-map-pin')
                    ->visibleOn('create')
                    ->schema([
                        Repeater::make('addresses_data')
                            ->label('')
                            ->addActionLabel('+ Додати адресу')
                            ->defaultItems(0)
                            ->schema([
                                TextInput::make('label')
                                    ->label('Назва')
                                    ->placeholder('Дім, Робота, Дача...')
                                    ->default('Адреса')
                                    ->required(),

                                Toggle::make('is_default')
                                    ->label('Основна адреса')
                                    ->onColor('success')
                                    ->columnSpanFull(),

                                Select::make('address_search')
                                    ->label('Адреса')
                                    ->searchable()
                                    ->dehydrated(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, callable $set) {
                                        if ($state && str_contains((string) $state, '|||')) {
                                            [$lat, $lng, $address] = explode('|||', $state, 3);
                                            $set('lat', $lat);
                                            $set('lng', $lng);
                                            $set('address', $address);
                                        }
                                    })
                                    ->getSearchResultsUsing(function (string $search) {
                                        if (strlen($search) < 3) return [];
                                        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                                            'q'            => $search . ', Київ',
                                            'format'       => 'json',
                                            'limit'        => 7,
                                            'countrycodes' => 'ua',
                                        ]);
                                        $response = @file_get_contents($url, false, stream_context_create([
                                            'http' => ['header' => "User-Agent: CRM/1.0\r\n"],
                                        ]));
                                        if (!$response) return [];
                                        $results = json_decode($response, true) ?? [];
                                        $options = [];
                                        foreach ($results as $r) {
                                            $value = ($r['lat'] ?? '') . '|||' . ($r['lon'] ?? '') . '|||' . ($r['display_name'] ?? '');
                                            $options[$value] = $r['display_name'] ?? '';
                                        }
                                        return $options;
                                    })
                                    ->placeholder('Почніть вводити вулицю...')
                                    ->columnSpanFull(),

                                TextInput::make('address')
                                    ->label('Адреса (можна редагувати)')
                                    ->required()
                                    ->placeholder('Оберіть з пошуку або введіть вручну')
                                    ->columnSpanFull(),

                                \Filament\Forms\Components\Hidden::make('lat'),
                                \Filament\Forms\Components\Hidden::make('lng'),

                                TextInput::make('address_entrance')->label('Під\'їзд'),
                                TextInput::make('address_apartment')->label('Кв/офіс'),
                                TextInput::make('address_floor')->label('Поверх'),

                                TextInput::make('delivery_comment')
                                    ->label('Коментар для доставки')
                                    ->placeholder('Домофон, код...')
                                    ->columnSpanFull(),
                            ])
                            ->columns(3)
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('ID')
                    // 🔥 ВИПРАВЛЕНО: Явно вказуємо таблицю clients для сортування і пошуку
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('clients.id', $direction))
                    ->searchable(query: fn ($query, $search) => $query->where('clients.id', 'like', "%{$search}%"))
                    ->width(50),

                Tables\Columns\TextColumn::make('name')
                    ->label("Ім'я")
                    // 🔥 ВИПРАВЛЕНО: Явно вказуємо таблицю clients
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('clients.name', $direction))
                    ->searchable(query: fn ($query, $search) => $query->where('clients.name', 'like', "%{$search}%")),

                Tables\Columns\TextColumn::make('project')
                    ->label('Проєкт')
                    ->badge()
                    ->getStateUsing(function ($record) {
                        $order = $record->orders()->latest('id')->first();
                        return $order?->projectData?->name;
                    })
                    ->color(function ($record) {
                        $order = $record->orders()->latest('id')->first();
                        return $order?->projectData?->color ?? 'gray';
                    })
                    ->sortable(query: function ($query, string $direction) {
                        return $query->orderBy(
                            \App\Models\Order::select('project')
                                ->whereColumn('client_id', 'clients.id')
                                ->latest('id')
                                ->limit(1),
                            $direction
                        );
                    })
                    // Телефон: бренд ховаємо, лишаємо ID / Ім'я / День та решту
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('active_order_progress')
                    ->label('День')
                    ->badge()
                    ->sortable(query: function ($query, string $direction) {
                        return $query->orderBy(
                            \App\Models\Order::select('end_date')
                                ->whereColumn('client_id', 'clients.id')
                                ->whereIn('status', ['active', 'new'])
                                ->orderBy('end_date', 'desc') 
                                ->limit(1),
                            $direction
                        );
                    })
                    ->color(function ($state) {
                        if (!$state) return 'gray'; 
                        
                        $parts = explode(' / ', $state);
                        if (count($parts) !== 2) return 'gray';

                        $current = (int)$parts[0];
                        $total = (int)$parts[1];

                        if ($current === 0) return 'gray';

                        $diff = $total - $current; 

                        if ($diff === 0) return 'danger';
                        if ($diff === 1) return 'warning';
                        
                        return 'success'; 
                    })
                    ->getStateUsing(function ($record) {
                        $order = $record->orders()
                            ->whereIn('status', ['active', 'new'])
                            ->latest('id')
                            ->first();

                        if (!$order) return null;

                        $allDays = $order->orderDays()
                            ->orderBy('date', 'asc')
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('Y-m-d'))
                            ->toArray();

                        $total = count($allDays);
                        if ($total === 0) return null;

                        $today = now()->format('Y-m-d');
                        $currentDayIndex = 0;

                        foreach ($allDays as $index => $date) {
                            if ($date <= $today) {
                                $currentDayIndex = $index + 1;
                            }
                        }

                        return "{$currentDayIndex} / {$total}";
                    }),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    // 🔥 ДОДАНО: Можливість безпечно шукати і по номеру телефону
                    ->searchable(query: fn ($query, $search) => $query->where('clients.phone', 'like', "%{$search}%"))
                    ->copyable(),
                
                Tables\Columns\TextColumn::make('balance')
                    ->label('Баланс')
                    ->money('UAH')
                    // 🔥 ВИПРАВЛЕНО: Явно вказуємо таблицю
                    ->sortable(query: fn ($query, $direction) => $query->orderBy('clients.balance', $direction))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('manager_comment')
                    ->label('Коментар')
                    ->limit(50)
                    ->tooltip(fn ($state) => $state)
                    ->wrap(),

                Tables\Columns\TextColumn::make('sales_source')
                    ->label('Джерело')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Залишив заявку РК' => 'success',
                        'Залишив заявку СЕО' => 'info',
                        'Повідомлення в соц мережі з реклами' => 'warning',
                        'Написав в телеграм' => 'info',
                        'Написав в вайбер' => 'success',
                        'Телефонний дзвінок' => 'primary',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('address')
                    ->label('Адреса')
                    ->getStateUsing(function ($record) {
                        $default = $record->addresses()->where('is_default', true)->first()
                            ?? $record->addresses()->first();
                        return $default?->address;
                    })
                    ->limit(20)
                    ->tooltip(function ($record) {
                        $default = $record->addresses()->where('is_default', true)->first()
                            ?? $record->addresses()->first();
                        return $default?->address;
                    }),
                
                Tables\Columns\IconColumn::make('has_cutlery')
                    ->label('Прибори')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('water_option')
                    ->label('Вода')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'with_water'          => 'З водою',
                        'without_water'       => 'Без води',
                        'water_without_lemon' => 'Вода без лимону',
                        default               => '—',
                    })
                    ->color(fn ($state) => match ($state) {
                        'with_water'          => 'info',
                        'without_water'       => 'gray',
                        'water_without_lemon' => 'warning',
                        default               => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('ant_comp_id')
                    ->label('Ant ID')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('active_order_progress', 'asc')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            AddressesRelationManager::class,
            ActivityLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}