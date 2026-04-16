<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\AllergenResource\Pages;
use App\Models\Allergen;
use App\Models\Ingredient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AllergenResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = Allergen::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Алергени';
    protected static ?string $pluralModelLabel = 'Алергени';
    protected static ?string $modelLabel = 'Алерген';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Назва алергену')
                    ->placeholder('Наприклад: Глютен, Лактоза, Горіхи')
                    ->required()
                    ->extraInputAttributes(['autocomplete' => 'off']),

                Forms\Components\Select::make('ingredients')
                    ->label('Інгредієнти з цим алергеном')
                    ->helperText('Або скористайся кнопкою «Застосувати до групи» вгорі для масового призначення.')
                    ->multiple()
                    ->relationship('ingredients', 'name')
                    ->searchable()
                    ->preload(false)
                    ->getSearchResultsUsing(fn (string $search) =>
                        Ingredient::where('name', 'like', "%{$search}%")->limit(50)->pluck('name', 'id')
                    ),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('ingredients_count')
                    ->label('Інгредієнтів')
                    ->counts('ingredients')
                    ->badge()
                    ->color('warning'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Створено')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
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
            'index'  => Pages\ListAllergens::route('/'),
            'create' => Pages\CreateAllergen::route('/create'),
            'edit'   => Pages\EditAllergen::route('/{record}/edit'),
        ];
    }
}
