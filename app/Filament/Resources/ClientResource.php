<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\ClientResource\RelationManagers\AddressesRelationManager;
use App\Models\Client;
use App\Models\MealType;
use Filament\Forms;
use Filament\Forms\Form;
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
use Filament\Forms\Set;

class ClientResource extends Resource
{
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
                                'Написав в месенджер' => 'Написав в месенджер',
                                'Телефонний дзвінок' => 'Телефонний дзвінок',
                            ])
                            ->searchable(),
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

                                $kcal = (int) $state;
                                $allMeals = MealType::all();
                                $mealTypeIds = [];

                                if ($kcal < 1200) {
                                    // 3 прийоми: Сніданок, Обід, Вечеря
                                    $mealTypeIds = $allMeals->filter(function ($meal) {
                                        $name = mb_strtolower($meal->name);
                                        return str_contains($name, 'сніданок') || 
                                               str_contains($name, 'обід') || 
                                               str_contains($name, 'вечеря');
                                    })->pluck('id')->toArray();
                                    
                                } elseif ($kcal < 1500) {
                                    // 4 прийоми: Сніданок, Обід, Перекус 2, Вечеря (без Перекусу 1)
                                    $mealTypeIds = $allMeals->filter(function ($meal) {
                                        $name = mb_strtolower($meal->name);
                                        return str_contains($name, 'сніданок') || 
                                               str_contains($name, 'обід') || 
                                               str_contains($name, 'перекус 2') || 
                                               str_contains($name, 'вечеря');
                                    })->pluck('id')->toArray();
                                    
                                } else {
                                    // 5 прийомів: Усі
                                    $mealTypeIds = $allMeals->pluck('id')->toArray();
                                }

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

                Section::make('Логістика')
                    ->description('Адреси доставки керуються у вкладці "Адреси доставки".')
                    ->schema([])->columns(1),

                Section::make('Для менеджера')
                    ->schema([
                        Textarea::make('manager_comment')
                            ->label('Коментар менеджера')
                            ->placeholder('Наприклад: Передзвонити клієнту')
                            ->rows(3)
                            ->columnSpanFull(),
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
                        'Написав в месенджер' => 'warning',
                        'Телефонний дзвінок' => 'primary',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('address')
                    ->label('Адреса')
                    ->limit(20)
                    ->tooltip(fn ($record) => $record->address),
                
                Tables\Columns\IconColumn::make('has_cutlery')
                    ->label('Прибори')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('active_order_progress', 'asc') 
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
            AddressesRelationManager::class,
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