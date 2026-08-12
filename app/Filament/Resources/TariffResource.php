<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\TariffResource\Pages;
use App\Models\Tariff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope; // 🔥 1. Імпорт для роботи з видаленими

class TariffResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Tariff::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Категорії тарифів';
    protected static ?string $pluralModelLabel = 'Категорії тарифів';
    protected static ?string $modelLabel = 'Категорія';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Параметри тарифу')
                    ->description('Визначте назву тарифного плану для розрахунку ціни за день.')
                    ->schema([
                        // 🔥 Вибір бренду (Тепер тягнеться з бази автоматично)
                        Forms\Components\Select::make('project')
                            ->label('Проєкт')
                            ->options(\App\Models\Project::all()->pluck('name', 'slug'))
                            ->required()
                            ->native(false)
                            ->prefixIcon('heroicon-o-building-storefront'),

                        // Назва тарифу
                        Forms\Components\TextInput::make('name')
                            ->label('Назва тарифу')
                            ->placeholder('Наприклад: Від 7 днів')
                            ->required(),

                        // Строк досі жив тільки в назві («Від 21 дня»), тож зовнішні
                        // системи змушені були її парсити. Тепер це окреме поле.
                        Forms\Components\TextInput::make('min_days')
                            ->label('Мінімум днів')
                            ->helperText('Менше цієї кількості замовлення за тарифом не оформити. Порожньо — без обмеження.')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('без обмеження'),

                        Forms\Components\Select::make('default_menu_plan_id')
                            ->label('План меню за замовчуванням')
                            ->helperText('Підставляється у нове замовлення з цим тарифом. Менеджер може змінити вручну.')
                            ->options(fn () => \App\Models\MenuPlan::orderBy('sort_order')->orderBy('id')->pluck('name', 'id'))
                            ->placeholder('— дефолтний план системи —')
                            ->searchable()
                            ->preload()
                            ->columnSpan(2),

                    ])->columns(2),

                Forms\Components\Section::make('Ціни по діапазонах калорій')
                    ->description('Скільки коштує 1 день харчування у цьому тарифі для кожного діапазону калорій. Рядки відповідають діапазонам, створеним у «Діапазони калорій».')
                    ->schema([
                        Forms\Components\Placeholder::make('no_ranges_warning')
                            ->label('')
                            ->columnSpanFull()
                            ->visible(fn () => \App\Models\CalorieRange::count() === 0)
                            ->content(new \Illuminate\Support\HtmlString(
                                '<div style="background:#7f1d1d; border:1px solid #ef4444; border-radius:6px; padding:8px 12px; color:#fee2e2; font-size:12px;">⚠️ Спочатку створи діапазони калорій у «Довідник → Діапазони калорій».</div>'
                            )),

                        Forms\Components\Repeater::make('prices')
                            ->relationship('prices')
                            ->label('')
                            ->columnSpanFull()
                            ->schema([
                                Forms\Components\Select::make('calorie_range_id')
                                    ->label('Діапазон калорій')
                                    ->options(fn () => \App\Models\CalorieRange::orderBy('min_kcal')->get()
                                        ->mapWithKeys(fn ($r) => [$r->id => "{$r->name} ({$r->min_kcal}–{$r->max_kcal} ккал)"]))
                                    ->required()
                                    ->distinct()
                                    ->validationMessages(['distinct' => 'Цей діапазон уже додано — не дублюй.'])
                                    ->searchable()
                                    ->preload(),

                                Forms\Components\TextInput::make('price_per_day')
                                    ->label('Ціна за 1 день')
                                    ->prefix('₴')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->reorderable(false)
                            ->defaultItems(0)
                            ->minItems(0)
                            ->visible(fn () => \App\Models\CalorieRange::count() > 0)
                            ->addActionLabel('Додати рядок'),

                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('fillMissingRanges')
                                ->label('Додати рядки для всіх діапазонів')
                                ->icon('heroicon-o-plus-circle')
                                ->color('info')
                                ->visible(fn () => \App\Models\CalorieRange::count() > 0)
                                ->action(function (Forms\Set $set, Forms\Get $get) {
                                    $existing = collect($get('prices') ?? [])
                                        ->pluck('calorie_range_id')
                                        ->filter()
                                        ->all();

                                    $newItems = $get('prices') ?? [];
                                    foreach (\App\Models\CalorieRange::orderBy('min_kcal')->get() as $r) {
                                        if (!in_array($r->id, $existing)) {
                                            $newItems[] = [
                                                'calorie_range_id' => $r->id,
                                                'price_per_day'    => 0,
                                            ];
                                        }
                                    }

                                    $set('prices', $newItems);
                                }),
                        ])->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Статус')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('Доступний для вибору')
                            ->default(true)
                            ->onColor('success'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // 🔥 Динамічна колонка з бази даних (Тягне назву і колір)
                Tables\Columns\TextColumn::make('projectData.name')
                    ->label('Проєкт')
                    ->badge()
                    ->color(fn ($record): string => $record->projectData?->color ?? 'gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Назва категорії')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активний')
                    ->boolean(),
            ])
            ->defaultSort('project')
            ->filters([
                // 🔥 2. Фільтр для перегляду видалених записів (Кошик)
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
                Tables\Actions\ForceDeleteAction::make()->label('')->tooltip('Видалити назавжди'),
                Tables\Actions\RestoreAction::make()->label('')->tooltip('Відновити'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\ForceDeleteBulkAction::make(), // Масове видалення назавжди
                    Tables\Actions\RestoreBulkAction::make(),     // Масове відновлення
                ]),
            ]);
    }

    // 🔥 4. Додаємо цей метод, щоб адмінка бачила видалені записи
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTariffs::route('/'),
            'create' => Pages\CreateTariff::route('/create'),
            'edit' => Pages\EditTariff::route('/{record}/edit'),
        ];
    }
}