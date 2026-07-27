<?php

namespace App\Filament\Resources;

use App\Traits\AllowCookAccess;

use App\Filament\Resources\IngredientResource\Pages;
use App\Models\Allergen;
use App\Models\Ingredient;
use App\Models\StockDocumentItem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class IngredientResource extends Resource
{
    use AllowCookAccess;
    protected static ?string $model = Ingredient::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 2;
    protected static ?string $navigationLabel = 'Інгредієнти';
    protected static ?string $pluralModelLabel = 'Інгредієнти';
    protected static ?string $modelLabel = 'Інгредієнт';
    
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Основна інформація')
                    ->schema([
                        FileUpload::make('photo')
                            ->label('Фото продукту')
                            ->image()
                            ->directory('ingredients')
                            ->columnSpan(1),

                        Section::make()
                            ->schema([
                                TextInput::make('name')
                                    ->label('Назва продукту')
                                    ->required()
                                    ->extraInputAttributes(['autocomplete' => 'off']),
                                
                                Select::make('group')
                                    ->label('Група/Тип')
                                    ->options(function () {
                                        return Ingredient::query()
                                            ->whereNotNull('group')
                                            ->where('group', '!=', '')
                                            ->distinct()
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

                                Select::make('allergens')
                                    ->label('Алергени')
                                    ->multiple()
                                    ->relationship('allergens', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->placeholder('— немає —'),

                                TextInput::make('price_per_kg')
                                    ->label('Базова ціна за кг (грн)')
                                    ->helperText('Використовується як середня, якщо ще не було закупів')
                                    ->numeric()
                                    ->prefix('₴'),
                                    
                                TextInput::make('yield_percent')
                                    ->label('Відсоток виходу (%)')
                                    ->numeric()
                                    ->default(100),
                            ])->columnSpan(2),
                    ])->columns(3),

                Section::make('КБЖВ на 100г')
                    ->schema([
                        TextInput::make('calories_100g')->label('Ккал')->numeric()->default(0),
                        TextInput::make('proteins_100g')->label('Білки')->numeric()->default(0),
                        TextInput::make('fats_100g')->label('Жири')->numeric()->default(0),
                        TextInput::make('carbs_100g')->label('Вуглеводи')->numeric()->default(0),
                    ])->columns(4),

                Section::make('Складський облік')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                TextInput::make('stock')
                                    ->label('Залишок на складі')
                                    ->formatStateUsing(fn ($state) => empty($state) ? '' : (float) $state)
                                    ->rule('regex:/^\d+(?:[.,]\d+)?$/')
                                    ->extraInputAttributes(['inputmode' => 'decimal'])
                                    ->required()
                                    ->columnSpan(2)
                                    ->helperText('Введіть точну кількість (наприклад 0.5 або 0,5).')
                                    ->dehydrateStateUsing(function ($state) {
                                        if (blank($state)) return null;
                                        $value = str_replace(',', '.', $state);
                                        return (float) $value;
                                    }),

                                Select::make('unit')
                                    ->label('Базова од. (склад)')
                                    ->options([
                                        'г' => 'Грами (г)', 'кг' => 'Кілограми (кг)', 'шт' => 'Штуки (шт)',
                                        'мл' => 'Мілілітри (мл)', 'л' => 'Літри (л)',
                                    ])
                                    ->required()
                                    ->live()
                                    // Тримаємо одиницю упаковки в тій же групі, що й базова
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $units = StockDocumentItem::compatibleUnits($state);
                                        $set('package_unit', $units[1] ?? $units[0] ?? 'г');
                                    })
                                    ->columnSpan(1),

                                // 📦 Упаковочний режим: закупник вводить упаковки,
                                // склад отримує базову одиницю.
                                Forms\Components\Toggle::make('is_packaged')
                                    ->label('Продається упаковками')
                                    ->helperText('Напр. сметана в упаковці 400 г — на приході вводимо к-сть упаковок і ціну за упаковку')
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Get $get, Forms\Set $set) {
                                        if ($state && blank($get('package_unit'))) {
                                            $units = StockDocumentItem::compatibleUnits($get('unit') ?: 'кг');
                                            $set('package_unit', $units[1] ?? $units[0] ?? 'г');
                                        }
                                    })
                                    ->columnSpan(3),

                                TextInput::make('package_weight')
                                    ->label('📦 Вміст однієї упаковки')
                                    ->numeric()
                                    ->required(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->suffix(fn (Forms\Get $get) => $get('package_unit') ?: '')
                                    ->helperText('Скільки важить/містить одна упаковка')
                                    ->columnSpan(2),

                                Forms\Components\Select::make('package_unit')
                                    ->label('Од. упаковки')
                                    ->options(fn (Forms\Get $get) => collect(
                                            StockDocumentItem::compatibleUnits($get('unit'))
                                        )->mapWithKeys(fn ($u) => [$u => $u])->all())
                                    ->default(fn (Forms\Get $get) => StockDocumentItem::compatibleUnits($get('unit') ?: 'кг')[1]
                                        ?? StockDocumentItem::compatibleUnits($get('unit') ?: 'кг')[0] ?? 'г')
                                    ->required(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->visible(fn (Forms\Get $get) => (bool) $get('is_packaged'))
                                    ->columnSpan(1),
                            ])
                    ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\ImageColumn::make('photo')->label('Фото')->circular(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->color('success')
                    ->weight('bold'),
                
                Tables\Columns\TextColumn::make('allergens.name')
                    ->label('Алергени')
                    ->badge()
                    ->color('warning')
                    ->default('—')
                    ->separator(','),

                Tables\Columns\TextColumn::make('group')->label('Група/Тип')->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Фрукти', 'Овочі', 'Зелень', 'Ягоди', 'Гриби', 'Сухофрукти' => 'success',
                        'М’ясо', 'М’ясні продукти', 'Субпродукти' => 'danger',
                        'Риба', 'Морепродукти', 'Молочні продукти', 'Напої', 'Сири' => 'info',
                        'Бакалія', 'Злаки та крупи', 'Борошно та вироби', 'Горіхи', 'Бобові', 
                        'Кондитерські вироби, солодощі', 'Соєві продукти', 'Глютеновмісні' => 'warning',
                        'Спеції та прянощі', 'Соуси', 'Системні', 'Інше' => 'gray',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('kbjv')->label('Б / Ж / В / Ккал')
                    ->getStateUsing(fn ($record): string => 
                        ($record->proteins_100g ?? 0) . ' / ' . ($record->fats_100g ?? 0) . ' / ' . ($record->carbs_100g ?? 0) . ' / ' . ($record->calories_100g ?? 0)
                    )->badge()->color('gray')->alignCenter(),

                // 🔥 Залишаємо середню ціну для довідника
                Tables\Columns\TextColumn::make('average_price')
                    ->label('Середня ціна UAH')
                    ->money('UAH')
                    ->description(fn($record) => "Фікс: " . number_format($record->price_per_kg, 2) . " ₴")
                    ->sortable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Наявність')
                    ->formatStateUsing(function ($state, $record) {
                        return (float) $state . ' ' . $record->unit;
                    })
                    ->color(fn ($record) => $record->stock <= 0 ? 'danger' : 'success')
                    ->weight('bold'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')
                    ->label('Група')
                    ->options(function () {
                        return Ingredient::query()
                            ->whereNotNull('group')
                            ->where('group', '!=', '')
                            ->distinct()
                            ->pluck('group', 'group')
                            ->toArray();
                    })
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array 
    { 
        return [
            IngredientResource\RelationManagers\UsageInDishesRelationManager::class,
        ]; 
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIngredients::route('/'),
            'create' => Pages\CreateIngredient::route('/create'),
            'edit' => Pages\EditIngredient::route('/{record}/edit'),
        ];
    }
}