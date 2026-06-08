<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PositionResource\Pages;
use App\Models\Position;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Section;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;

class PositionResource extends Resource
{
    protected static ?string $model = Position::class;

    protected static ?string $navigationLabel = 'Посади';
    protected static ?string $pluralModelLabel = 'Посади';
    protected static ?string $modelLabel = 'Посада';
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        return auth()->user()->role === 'admin';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Посада')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->placeholder('напр. Таргетолог'),

                    Select::make('color')
                        ->label('Колір бейджа')
                        ->options([
                            'gray'    => 'Сірий',
                            'primary' => 'Синій',
                            'info'    => 'Блакитний',
                            'success' => 'Зелений',
                            'warning' => 'Жовтий',
                            'danger'  => 'Червоний',
                        ])
                        ->default('gray')
                        ->required(),

                    Select::make('payment_type')
                        ->label('Тип оплати')
                        ->options([
                            'per_shift' => 'За зміну (по днях у табелі)',
                            'per_month' => 'За місяць (оклад)',
                        ])
                        ->default('per_shift')
                        ->required()
                        ->live()
                        ->helperText('Помісячні не потрапляють у табель змін.'),

                    TextInput::make('monthly_working_days')
                        ->label('Робочих днів на місяць')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(31)
                        ->default(22)
                        ->required(fn (Get $get) => $get('payment_type') === 'per_month')
                        ->visible(fn (Get $get) => $get('payment_type') === 'per_month')
                        ->helperText('Для рознесення окладу по днях в аналітиці.'),

                    Toggle::make('split_by_brands')
                        ->label('Розділяти витрати по брендах')
                        ->helperText('Увімкни для ролей, чиї витрати треба рахувати окремо по бренду (напр. таргетолог). Вимкнено — ЗП рознесеться пропорційно виручці брендів.')
                        ->default(false)
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Активна')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->badge()
                    ->color(fn (Position $record) => $record->color)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('payment_type')
                    ->label('Оплата')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'per_month' ? 'За місяць' : 'За зміну')
                    ->color(fn (string $state) => $state === 'per_month' ? 'info' : 'gray'),

                TextColumn::make('monthly_working_days')
                    ->label('Роб. днів')
                    ->placeholder('—')
                    ->alignCenter(),

                IconColumn::make('split_by_brands')
                    ->label('По брендах')
                    ->boolean(),

                IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean(),

                TextColumn::make('employees_count')
                    ->label('Співробітників')
                    ->counts('employees')
                    ->alignCenter(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label('')->tooltip('Змінити'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPositions::route('/'),
            'create' => Pages\CreatePosition::route('/create'),
            'edit'   => Pages\EditPosition::route('/{record}/edit'),
        ];
    }
}
