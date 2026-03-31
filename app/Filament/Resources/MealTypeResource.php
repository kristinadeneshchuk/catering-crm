<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MealTypeResource\Pages;
use App\Models\MealType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class MealTypeResource extends Resource
{
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
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Прийом їжі'),
                
                // Відображаємо відсоток у таблиці
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