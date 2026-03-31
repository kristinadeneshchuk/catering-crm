<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DishResource\Pages;
use App\Models\Dish;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Grid;

class DishResource extends Resource
{
    protected static ?string $model = Dish::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Страви (Техкарти)';
    protected static ?string $pluralModelLabel = 'Страви (Техкарти)';
    protected static ?string $modelLabel = 'Страва';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основна інформація')
                    ->schema([
                        FileUpload::make('photo')
                            ->image()
                            ->directory('dishes')
                            ->label('Фото страви/НФ')
                            ->columnSpan(1),

                        Section::make()
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->label('Назва страви')
                                    ->extraInputAttributes(['autocomplete' => 'off'])
                                    ->datalist(Dish::latest()->limit(5)->pluck('name')->toArray()),

                                // Список груп підтягується з бази
                                Select::make('group')
                                    ->label('Група')
                                    ->options(function () {
                                        return Dish::query()
                                            ->distinct()
                                            ->whereNotNull('group')
                                            ->pluck('group', 'group')
                                            ->toArray();
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->createOptionForm([
                                        TextInput::make('group')
                                            ->label('Назва нової групи')
                                            ->required(),
                                    ])
                                    ->createOptionUsing(function (array $data) {
                                        return $data['group'];
                                    }),

                                TextInput::make('base_weight_g')
                                    ->numeric()
                                    ->required()
                                    ->label('Вага виходу (г)')
                                    ->helperText('Вкажіть вагу готової страви (після уварки).')
                                    ->extraInputAttributes(['autocomplete' => 'off']),

                                Toggle::make('is_semi_finished')
                                    ->label('Це напівфабрикат (НФ)')
                                    ->helperText('Дозволяє використовувати цю страву як інгредієнт в інших техкартах')
                                    ->default(false),
                            ])->columnSpan(2),
                    ])->columns(3),

                Section::make('Склад страви (Рецептура)')
                    ->description('Додавайте продукти або інші напівфабрикати.')
                    ->schema([
                        Repeater::make('dishIngredients')
                            ->relationship()
                            ->label('Складові')
                            ->live()
                            ->schema([
                                Select::make('type')
                                    ->label('Тип')
                                    ->options([
                                        'product' => 'Продукт',
                                        'pf' => 'НФ',
                                    ])
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $set('ingredient_id', null);
                                        $set('child_dish_id', null);
                                    })
                                    ->default('product')
                                    ->columnSpan(1),

                                Select::make('ingredient_id')
                                    ->relationship('ingredient', 'name', fn ($query) => $query->whereNotNull('name'))
                                    ->label('Продукт')
                                    ->searchable()
                                    ->preload()
                                    ->visible(fn (Forms\Get $get) => $get('type') === 'product')
                                    ->required(fn (Forms\Get $get) => $get('type') === 'product')
                                    ->columnSpan(2),

                                Select::make('child_dish_id')
                                    ->label('Напівфабрикат')
                                    ->options(fn () => Dish::where('is_semi_finished', true)->whereNotNull('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->preload()
                                    ->getOptionLabelUsing(fn ($value): ?string => Dish::find($value)?->name)
                                    ->visible(fn (Forms\Get $get) => $get('type') === 'pf')
                                    ->required(fn (Forms\Get $get) => $get('type') === 'pf')
                                    ->columnSpan(2),

                                TextInput::make('net_weight_g')
                                    ->numeric()
                                    ->required()
                                    ->label('Нетто (г)')
                                    ->live(onBlur: true)
                                    ->extraInputAttributes(['autocomplete' => 'off'])
                                    ->columnSpan(1),
                            ])
                            ->columns(4)
                            ->reorderable()
                            ->collapsible()
                            ->collapsed()
                            ->addActionLabel('+ Додати інгредієнт')
                            ->extraItemActions([
                                \Filament\Forms\Components\Actions\Action::make('preview')
                                    ->icon('heroicon-o-eye')
                                    ->tooltip('Деталі')
                                    ->color('gray')
                                    ->slideOver()
                                    ->modalWidth('md')
                                    ->modalSubmitAction(false)
                                    ->modalCancelActionLabel('Закрити')
                                    ->modalHeading(function (array $arguments, Repeater $component): string {
                                        $item = $component->getState()[$arguments['item']] ?? [];
                                        if (($item['type'] ?? 'product') === 'pf' && !empty($item['child_dish_id'])) {
                                            return Dish::find($item['child_dish_id'])?->name ?? 'НФ';
                                        }
                                        return \App\Models\Ingredient::find($item['ingredient_id'] ?? null)?->name ?? 'Інгредієнт';
                                    })
                                    ->modalContent(function (array $arguments, Repeater $component): \Illuminate\Contracts\View\View {
                                        $item = $component->getState()[$arguments['item']] ?? [];
                                        $type = $item['type'] ?? 'product';
                                        $netWeight = $item['net_weight_g'] ?? 0;

                                        if ($type === 'pf' && !empty($item['child_dish_id'])) {
                                            $dish = Dish::with('dishIngredients.ingredient')->find($item['child_dish_id']);
                                            return view('filament.modals.dish-preview', compact('dish', 'netWeight'));
                                        }

                                        $ingredient = \App\Models\Ingredient::find($item['ingredient_id'] ?? null);
                                        return view('filament.modals.ingredient-preview', compact('ingredient', 'netWeight'));
                                    }),
                            ])
                            ->itemLabel(function (array $state): ?string {
                                $weight = $state['net_weight_g'] ?? 0;
                                if (($state['type'] ?? 'product') === 'pf') {
                                    $name = isset($state['child_dish_id'])
                                        ? Dish::find($state['child_dish_id'])?->name
                                        : null;
                                    return '[НФ] ' . ($name ?? 'НФ') . ($weight ? ' — ' . $weight . 'г' : '');
                                }
                                $name = isset($state['ingredient_id'])
                                    ? \App\Models\Ingredient::find($state['ingredient_id'])?->name
                                    : null;
                                return '🍎 ' . ($name ?? 'Продукт') . ($weight ? ' — ' . $weight . 'г' : '');
                            }),

                        Placeholder::make('total_net_weight')
                            ->label('')
                            ->content(function (Forms\Get $get) {
                                $total = collect($get('dishIngredients'))
                                    ->sum(fn ($item) => (float) ($item['net_weight_g'] ?? 0));
                                return new \Illuminate\Support\HtmlString(
                                    '<span style="font-size:13px;color:#94a3b8;">Загальна нетто вага:</span> '
                                    . '<strong style="font-size:15px;">' . $total . ' г</strong>'
                                );
                            })
                            ->live(),
                    ]),

                Section::make('Економіка та Поживність (Підсумок)')
                    ->description('Розраховано на основі поточного складу та вказаної ваги виходу')
                    ->collapsible()
                    ->schema([
                        Grid::make(5)
                            ->schema([
                                Placeholder::make('total_cost')
                                    ->label('Собівартість (Загальна)')
                                    ->content(fn ($record) => $record ? "₴ " . number_format($record->total_cost, 2) : "—"),

                                Placeholder::make('total_kcal')
                                    ->label('Ккал (Загальні)')
                                    ->content(fn ($record) => $record ? number_format($record->total_kcal, 1) : "—"),

                                Placeholder::make('total_prot')
                                    ->label('Білки (Загальні)')
                                    ->content(fn ($record) => $record ? number_format($record->total_prot, 1) : "—"),

                                Placeholder::make('total_fat')
                                    ->label('Жири (Загальні)')
                                    ->content(fn ($record) => $record ? number_format($record->total_fat, 1) : "—"),

                                Placeholder::make('total_carb')
                                    ->label('Вуглеводи (Загальні)')
                                    ->content(fn ($record) => $record ? number_format($record->total_carb, 1) : "—"),

                                Placeholder::make('per_100_cost')
                                    ->label('Ціна за 100г')
                                    ->content(function ($record) {
                                        if (!$record || $record->base_weight_g <= 0) return "—";
                                        return "₴ " . number_format(($record->total_cost / $record->base_weight_g) * 100, 2);
                                    })
                                    ->extraAttributes(['class' => 'text-gray-500']),

                                Placeholder::make('per_100_kcal')
                                    ->label('Ккал на 100г')
                                    ->content(function ($record) {
                                        if (!$record || $record->base_weight_g <= 0) return "—";
                                        return number_format(($record->total_kcal / $record->base_weight_g) * 100, 1);
                                    })
                                    ->extraAttributes(['class' => 'font-bold text-success-600']),

                                Placeholder::make('per_100_prot')
                                    ->label('Білки на 100г')
                                    ->content(function ($record) {
                                        if (!$record || $record->base_weight_g <= 0) return "—";
                                        return number_format(($record->total_prot / $record->base_weight_g) * 100, 1);
                                    }),

                                Placeholder::make('per_100_fat')
                                    ->label('Жири на 100г')
                                    ->content(function ($record) {
                                        if (!$record || $record->base_weight_g <= 0) return "—";
                                        return number_format(($record->total_fat / $record->base_weight_g) * 100, 1);
                                    }),

                                Placeholder::make('per_100_carb')
                                    ->label('Вуглев. на 100г')
                                    ->content(function ($record) {
                                        if (!$record || $record->base_weight_g <= 0) return "—";
                                        return number_format(($record->total_carb / $record->base_weight_g) * 100, 1);
                                    }),
                            ])
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo')
                    ->circular()
                    ->label('Фото'),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                // === ЗМІНЕНО: ТУТ ТЕПЕР "НФ" ===
                Tables\Columns\TextColumn::make('group')
                    ->label('Група')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'НФ' => 'warning',              // Якщо буде "НФ"
                        'ПФ' => 'warning',              // Якщо раптом залишиться старе "ПФ"
                        'Напівфабрикати' => 'warning',  // Якщо залишиться довге
                        'Десерти' => 'success',
                        'Сніданки' => 'info',
                        'Перші страви' => 'primary',
                        'Основні страви' => 'success',
                        'Гарніри' => 'info',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total_cost')
                    ->label('Собівартість')
                    ->money('UAH')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('base_weight_g')
                    ->label('Вага (г)')
                    ->suffix(' г'),

                Tables\Columns\IconColumn::make('is_semi_finished')
                    ->label('НФ')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\Filter::make('is_semi_finished')
                    ->label('Тільки НФ')
                    ->query(fn ($query) => $query->where('is_semi_finished', true))
                    ->toggle(),

                Tables\Filters\SelectFilter::make('group')
                    ->label('Група')
                    ->options(function () {
                        return Dish::query()
                            ->distinct()
                            ->whereNotNull('group')
                            ->pluck('group', 'group')
                            ->toArray();
                    })
                    ->searchable(),
            ])
                ->actions([
                    Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                    Tables\Actions\ReplicateAction::make()
                        ->label('')
                        ->icon('heroicon-o-document-duplicate')
                        ->color('gray')
                        ->tooltip('Дублювати')
                        ->excludeAttributes(['photo'])
                        ->beforeReplicaSaved(function (Dish $replica): void {
                            $replica->name = $replica->name . ' (копія)';
                        })
                        ->successNotificationTitle('Страву продубльовано'),
                    Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
                ])
                ->bulkActions([
                    Tables\Actions\BulkActionGroup::make([
                        Tables\Actions\DeleteBulkAction::make(),
                    ]),
                ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDishes::route('/'),
            'create' => Pages\CreateDish::route('/create'),
            'edit' => Pages\EditDish::route('/{record}/edit'),
        ];
    }
}
