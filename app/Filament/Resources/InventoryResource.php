<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\RelationManagers\ItemsRelationManager;
use App\Filament\Resources\InventoryResource\Pages;
use App\Models\Inventory;
use App\Models\Ingredient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Fieldset;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;
    protected static ?string $navigationGroup = 'Склад';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Інвентаризації';
    protected static ?string $pluralModelLabel = 'Інвентаризації';
    protected static ?string $modelLabel = 'Інвентаризація';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Параметри інвентаризації')
                    ->schema([
                        DateTimePicker::make('operation_date')
                            ->label('Дата та час проведення')
                            ->default(now())
                            ->required(),

                        ToggleButtons::make('type')
                            ->label('Тип інвентаризації')
                            ->options([
                                'full' => 'Повна (всі товари на складі)',
                                'partial' => 'Часткова (обрані категорії)',
                            ])
                            ->default('full')
                            ->inline()
                            ->colors([
                                'full' => 'success',
                                'partial' => 'warning',
                            ])
                            ->live()
                            ->required()
                            ->columnSpanFull(),

                        Fieldset::make('Налаштування часткової інвентаризації')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'partial')
                            ->columnSpanFull()
                            ->schema([
                                Toggle::make('include_packagings')
                                    ->label('📦 Додати упаковку та госптовари')
                                    ->default(false)
                                    ->columnSpanFull(),

                                CheckboxList::make('selected_groups')
                                    ->label('🍏 Категорії продуктів')
                                    ->options(function () {
                                        return Ingredient::whereNotNull('group')
                                            ->distinct()
                                            ->pluck('group', 'group')
                                            ->toArray();
                                    })
                                    ->columns(4) // 4 колонки для компактності
                                    ->gridDirection('row')
                                    ->bulkToggleable() // 🔥 КНОПКА "ВИБРАТИ ВСІ"
                                    ->columnSpanFull(),
                            ]),

                        Textarea::make('comment')
                            ->label('Коментар')
                            ->columnSpanFull(),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('№')->sortable(),
                Tables\Columns\TextColumn::make('operation_date')->label('Дата')->dateTime('d.m.Y H:i')->sortable(),
                
                Tables\Columns\TextColumn::make('type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'full' ? 'Повна' : 'Часткова')
                    ->color(fn ($state) => $state === 'full' ? 'primary' : 'warning'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === 'draft' ? 'Відкрита' : 'Проведена')
                    ->color(fn ($state) => $state === 'draft' ? 'warning' : 'success'),

                Tables\Columns\TextColumn::make('total_surplus')
                    ->label('Надлишок')
                    ->money('UAH')
                    ->color('success')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('total_shortage')
                    ->label('Нестача')
                    ->money('UAH')
                    ->color('danger')
                    ->weight('bold'),
            ])
            ->defaultSort('id', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->label('Відкрити'),
            ]);
    }

    // 🔥 ДОДАНО: Підключення таблиці з товарами
    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'edit' => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}