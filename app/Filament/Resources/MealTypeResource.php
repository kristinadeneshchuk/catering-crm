<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\MealTypeResource\Pages;
use App\Models\MealType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\ColorPicker;

class MealTypeResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = MealType::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Типи прийомів їжі';
    protected static ?string $pluralModelLabel = 'Типи прийомів їжі';
    protected static ?string $modelLabel = 'Тип прийому їжі';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Назва')
                    ->placeholder('Наприклад: Сніданок')
                    ->required()
                    ->extraInputAttributes(['autocomplete' => 'off'])
                    ->datalist(
                        MealType::latest()->limit(5)->pluck('name')->toArray()
                    ),
                
                Forms\Components\TextInput::make('sort_order')
                    ->label('Порядок сортування')
                    ->helperText('1 — найраніше, 5 — найпізніше')
                    ->numeric()
                    ->default(1),

                // === НОВЕ ПОЛЕ: ВІДСОТОК КАЛОРІЙНОСТІ ===
                Forms\Components\TextInput::make('energy_percent')
                    ->label('% від денного раціону')
                    ->helperText('Наприклад: 25 для сніданку. Використовується для перерахунку порцій.')
                    ->numeric()
                    ->suffix('%')
                    ->required()
                    ->default(20),

                // === ДРУГА ВЕРСІЯ ФАСУВАННЯ ===
                // Тут енергія бокса задається прямо в кілокалоріях, а не
                // відсотком. Завдяки цьому у страви рівно дві ваги замість
                // однієї на кожен калораж.
                Forms\Components\Fieldset::make('Розмір бокса (друга версія фасування)')
                    ->schema([
                        Forms\Components\TextInput::make('box_kcal_std')
                            ->label('Звичайна порція, ккал')
                            ->helperText('Напр. 400 для сніданку, 200 для снека, 600 для обіду.')
                            ->numeric()
                            ->suffix('ккал'),

                        Forms\Components\TextInput::make('box_kcal_large')
                            ->label('Велика порція, ккал')
                            ->helperText('Напр. 600 для сніданку, 400 для снека, 800 для обіду.')
                            ->numeric()
                            ->suffix('ккал'),

                        Forms\Components\Placeholder::make('box_hint')
                            ->label('')
                            ->columnSpanFull()
                            ->content('Порожньо — прийом у другій версії не бере участі. На стару фасовку ці поля не впливають.'),
                    ])->columns(2),

                ColorPicker::make('color')
                    ->label('Колір (для стікерів)')
                    ->helperText('Колір кружечка на стікері заміни')
                    ->default('#94a3b8'),

                Forms\Components\TextInput::make('short_letter')
                    ->label('Літера (для стікерів)')
                    ->helperText('1-2 символи: С, О, П, В, Д...')
                    ->maxLength(4)
                    ->default('?'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Прийом їжі'),

                Tables\Columns\ColorColumn::make('color')
                    ->label('Колір'),

                Tables\Columns\TextColumn::make('short_letter')
                    ->label('Літера')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('energy_percent')
                    ->label('% Енергії')
                    ->suffix('%')
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')->label('Порядок')->sortable(),
            ])
            ->defaultSort('sort_order', 'asc')
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Tables\Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMealTypes::route('/'),
            'create' => Pages\CreateMealType::route('/create'),
            'edit' => Pages\EditMealType::route('/{record}/edit'),
        ];
    }
}