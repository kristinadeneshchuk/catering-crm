<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Filament\Resources\OrderResource\RelationManagers\TransactionsRelationManager;
use App\Filament\Resources\OrderResource\RelationManagers\DeliveryCalendarRelationManager;
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
use Illuminate\Database\Eloquent\Builder;

class OrderResource extends Resource
{
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
                            ->afterStateUpdated(fn (Set $set, Get $get) => static::updateOrderTotals($set, $get)),

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

                // === СЕКЦІЯ 3: КАЛЕНДАР ===
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
                TextColumn::make('id')->label('ID')->sortable(),

                // 🔥 Динамічна колонка проєкту (тягне назву і колір з БД)
                TextColumn::make('projectData.name')
                    ->label('Проєкт')
                    ->badge()
                    ->color(fn ($record): string => $record->projectData?->color ?? 'gray')
                    ->sortable(),

                TextColumn::make('client.name')
                    ->label('Клієнт')
                    ->description(fn ($record) => "ID: {$record->client_id}")
                    ->searchable()
                    ->sortable(),


                TextColumn::make('current_day')
                    ->label('День')
                    ->getStateUsing(function ($record) {
                        // Отримуємо всі вибрані дні з бази, відсортовані за часом
                        $allDays = $record->orderDays()
                            ->orderBy('date', 'asc')
                            ->pluck('date')
                            ->map(fn($date) => Carbon::parse($date)->format('Y-m-d'))
                            ->toArray();

                        $total = count($allDays);
                        if ($total === 0) return '0 / 0';

                        $today = Carbon::now()->format('Y-m-d');
                        $currentDayNumber = 0;

                        // Якщо сьогоднішня дата є в масиві або вже пройшла
                        foreach ($allDays as $index => $date) {
                            if ($date <= $today) {
                                $currentDayNumber = $index + 1;
                            }
                        }

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

                TextColumn::make('total_price')
                    ->label('Сума')
                    ->money('UAH')
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
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            TransactionsRelationManager::class,
        ];
    }

    protected static function updateOrderTotals(Set $set, Get $get)
    {
        $calories = (int) $get('calories');
        $tariffId = $get('tariff_id');
        $duration = (int) $get('duration') ?: 1;

        if ($calories && $tariffId) {
            $range = CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();

            if ($range) {
                $priceEntry = TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();

                if ($priceEntry) {
                    $set('total_price', $priceEntry->price_per_day * $duration);
                    return;
                }
            }
        }
        $set('total_price', 0);
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