<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Filament\Resources\ClientResource\RelationManagers\OrdersRelationManager;
use App\Models\Client;
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
                    ->description('Виберіть, які прийоми їжі отримує клієнт. Система автоматично перерахує вагу порцій.')
                    ->schema([
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
                            ->default(fn () => \App\Models\MealType::pluck('id')->toArray())
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

                Section::make('Параметри раціону та логістика')
                    ->schema([
                        TextInput::make('target_kcal')
                            ->label('Цільові калорії')
                            ->numeric()
                            ->default(0)
                            ->suffix('ккал'),
                            
                        Textarea::make('address')
                            ->label('Адреса доставки')
                            ->columnSpanFull(),

                        Textarea::make('delivery_comment')
                            ->label('Коментар для доставки')
                            ->placeholder('Напр.: код домофону 45, залишити у консьєржа, зателефонувати за 5 хв.')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(1),

                // 🔥 НОВА СЕКЦІЯ: Коментар менеджера
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
                    ->sortable()
                    ->searchable()
                    ->width(50),

                Tables\Columns\TextColumn::make('name')
                    ->label("Ім'я")
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('active_order_progress')
                    ->label('День')
                    ->badge()
                    ->color('info')
                    ->getStateUsing(function ($record) {
                        // Шукаємо останнє актуальне замовлення (Активне або Нове)
                        $order = $record->orders()
                            ->whereIn('status', ['active', 'new'])
                            ->latest('id')
                            ->first();

                        if (!$order) {
                            return null;
                        }

                        // Отримуємо всі вибрані дні з календаря цього замовлення
                        $allDays = $order->orderDays()
                            ->orderBy('date', 'asc')
                            ->pluck('date')
                            ->map(fn($date) => \Carbon\Carbon::parse($date)->format('Y-m-d'))
                            ->toArray();

                        $total = count($allDays);
                        if ($total === 0) return null;

                        $today = now()->format('Y-m-d');
                        $currentDayIndex = 0;

                        // Рахуємо порядковий номер дня відносно сьогодні
                        foreach ($allDays as $index => $date) {
                            if ($date <= $today) {
                                $currentDayIndex = $index + 1;
                            }
                        }

                        return "{$currentDayIndex} / {$total}";
                    }),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Телефон')
                    ->copyable(),
                
                Tables\Columns\TextColumn::make('balance')
                    ->label('Баланс')
                    ->money('UAH')
                    ->sortable()
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),

                // 🔥 ДОДАНО: Коментар менеджера замість соцмереж
                Tables\Columns\TextColumn::make('manager_comment')
                    ->label('Коментар')
                    ->limit(50) // Обрізаємо довгий текст
                    ->tooltip(fn ($state) => $state) // Повний текст при наведенні
                    ->wrap(), // Дозволяємо перенос тексту на новий рядок

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
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            OrdersRelationManager::class,
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