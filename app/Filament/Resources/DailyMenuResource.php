<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DailyMenuResource\Pages;
use App\Models\DailyMenu;
use App\Models\Setting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

class DailyMenuResource extends Resource
{
    protected static ?string $model = DailyMenu::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Циклічне меню';
    protected static ?string $pluralModelLabel = 'Циклічне меню';
    protected static ?string $modelLabel = 'День меню';

    /**
     * Обмеження доступу: тільки для Адміністраторів та Менеджерів.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Параметри дня')
                    ->description('Вкажіть номер дня в циклі. Система автоматично повторюватиме цей раціон згідно з налаштуваннями бізнесу.')
                    ->schema([
                        TextInput::make('day_number')
                            ->label('Номер дня в циклі')
                            ->placeholder('Наприклад: 1')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            // ДИНАМІЧНИЙ ЛІМІТ: Беремо значення з налаштувань
                            ->maxValue(fn () => (int) Setting::where('key', 'menu_cycle_days')->value('value') ?: 24)
                            ->unique(ignoreRecord: true) 
                            ->validationMessages([
                                'max' => 'Ви не можете створити день №:value, оскільки тривалість циклу в налаштуваннях обмежена :max днями.',
                                'unique' => 'Меню для цього дня вже існує.',
                            ])
                            ->helperText(fn () => 'Максимально допустимий день зараз: ' . (Setting::where('key', 'menu_cycle_days')->value('value') ?: 24)),
                    ]),
                
                Forms\Components\Section::make('Склад раціону')
                    ->schema([
                        Repeater::make('menuItems')
                            ->relationship()
                            ->label('Страви на цей день')
                            ->schema([
                                Select::make('meal_type_id')
                                    ->relationship('mealType', 'name')
                                    ->required()
                                    ->label('Прийом їжі'),
                                    
                                Select::make('dish_id')
                                    ->relationship('dish', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->label('Страва'),
                            ])
                            ->columns(2)
                            ->itemLabel(fn (array $state): ?string => 
                                $state['dish_id'] ? \App\Models\Dish::find($state['dish_id'])?->name : null
                            )
                            ->collapsible() 
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('day_number')
                    ->label('День циклу')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('menu_items_count') 
                    ->counts('menuItems')
                    ->label('Кількість страв')
                    ->badge()
                    ->color('warning')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Востаннє оновлено')
                    ->dateTime('d.m.Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('day_number', 'asc') 
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDailyMenus::route('/'),
            'create' => Pages\CreateDailyMenu::route('/create'),
            'edit' => Pages\EditDailyMenu::route('/{record}/edit'),
        ];
    }
}