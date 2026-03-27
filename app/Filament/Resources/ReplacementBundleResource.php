<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReplacementBundleResource\Pages;
use App\Models\ReplacementBundle;
use App\Models\Ingredient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReplacementBundleResource extends Resource
{
    protected static ?string $model = ReplacementBundle::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Шаблони замін';
    protected static ?string $pluralModelLabel = 'Шаблони замін';
    protected static ?string $modelLabel = 'Шаблон замін';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Шаблон замін')
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва шаблону')
                            ->placeholder('Наприклад: Безлактозний, Безглютеновий')
                            ->required()
                            ->extraInputAttributes(['autocomplete' => 'off']),

                        Textarea::make('description')
                            ->label('Опис')
                            ->placeholder('Коли застосовувати цей шаблон...')
                            ->rows(2),

                        Repeater::make('items')
                            ->relationship()
                            ->label('Правила замін')
                            ->schema([
                                Select::make('original_ingredient_id')
                                    ->label('Оригінальний інгредієнт')
                                    ->options(Ingredient::orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->required(),
                                Select::make('replacement_ingredient_id')
                                    ->label('Замінити на')
                                    ->options(Ingredient::orderBy('name')->pluck('name', 'id'))
                                    ->searchable()
                                    ->required(),
                            ])
                            ->columns(2)
                            ->addActionLabel('+ Додати правило')
                            ->reorderable(false),
                    ]),
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
                Tables\Columns\TextColumn::make('description')
                    ->label('Опис')
                    ->limit(50)
                    ->color('gray'),
                Tables\Columns\TextColumn::make('items_count')
                    ->label('Правил')
                    ->counts('items')
                    ->badge()
                    ->color('info'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Створено')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index'  => Pages\ListReplacementBundles::route('/'),
            'create' => Pages\CreateReplacementBundle::route('/create'),
            'edit'   => Pages\EditReplacementBundle::route('/{record}/edit'),
        ];
    }
}
