<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackagingResource\Pages;
use App\Models\Packaging;
use App\Models\Project;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Grid;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Table;
use Filament\Tables\Actions;
use Filament\Tables\Filters\SelectFilter;

class PackagingResource extends Resource
{
    protected static ?string $model = Packaging::class;

    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 9;
    protected static ?string $navigationLabel = 'Упаковка та госптовари';
    protected static ?string $pluralModelLabel = 'Упаковка та госптовари';
    protected static ?string $modelLabel = 'Упаковка / Госптовар';

    public static function form(Form $form): Form
    {
        return $form->schema([

            Section::make('Основна інформація')
                ->schema([
                    TextInput::make('name')
                        ->label('Назва')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull(),

                    Grid::make(3)->schema([
                        TextInput::make('unit')
                            ->label('Одиниця виміру')
                            ->default('шт')
                            ->required(),

                        TextInput::make('price')
                            ->label('Ціна за одиницю (₴)')
                            ->numeric()
                            ->default(0)
                            ->step(0.01),

                        Select::make('project')
                            ->label('Проєкт / Бізнес')
                            ->options(fn () => Project::where('is_active', true)->pluck('name', 'slug'))
                            ->placeholder('Загальне (всі проєкти)')
                            ->nullable(),
                    ]),
                ]),

            Section::make('Тип пакування')
                ->description('Заповнюйте тільки для упаковки їжі. Госптовари залишайте без типу.')
                ->schema([

                    Select::make('packaging_type')
                        ->label('Тип упаковки')
                        ->options(Packaging::TYPES)
                        ->placeholder('— Госптовар (не вказувати) —')
                        ->nullable()
                        ->live()
                        ->columnSpanFull(),

                    Grid::make(2)->schema([
                        TextInput::make('capacity')
                            ->label('Ємність (обʼєм / вага)')
                            ->numeric()
                            ->step(0.01)
                            ->placeholder('Наприклад: 550')
                            ->visible(fn (Get $get) => in_array($get('packaging_type'), ['бокс', 'супник', 'пляшка', 'стакан-десерт', 'соусник'])),

                        Select::make('capacity_unit')
                            ->label('Одиниця ємності')
                            ->options(['мл' => 'мл', 'г' => 'г'])
                            ->default('мл')
                            ->visible(fn (Get $get) => in_array($get('packaging_type'), ['бокс', 'супник', 'пляшка', 'стакан-десерт', 'соусник'])),
                    ]),

                    Select::make('pair_id')
                        ->label('Пара (кришка / ковпачок)')
                        ->helperText('Вкажіть кришку або ковпачок, що йде в комплекті. Система списуватиме їх разом автоматично.')
                        ->options(fn () => Packaging::whereIn('packaging_type', ['кришка', 'ковпачок', 'кришка-супник', 'кришка-десерт'])
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->toArray())
                        ->getSearchResultsUsing(fn (string $search) => Packaging::whereIn('packaging_type', ['кришка', 'ковпачок', 'кришка-супник', 'кришка-десерт'])
                            ->where('name', 'like', "%{$search}%")
                            ->orderBy('name')
                            ->limit(50)
                            ->pluck('name', 'id')
                            ->toArray())
                        ->getOptionLabelUsing(fn ($value) => Packaging::find($value)?->name)
                        ->searchable()
                        ->nullable()
                        ->placeholder('— Без пари —')
                        ->visible(fn (Get $get) => in_array($get('packaging_type'), ['бокс', 'супник', 'пляшка', 'стакан-десерт', 'соусник']))
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('packaging_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Packaging::TYPES[$state] ?? '—')
                    ->color(fn ($state) => match($state) {
                        'бокс'          => 'success',
                        'кришка'        => 'gray',
                        'супник'        => 'warning',
                        'кришка-супник' => 'gray',
                        'пляшка'        => 'info',
                        'ковпачок'      => 'gray',
                        'стакан-десерт' => 'primary',
                        'кришка-десерт' => 'gray',
                        'соусник'       => 'warning',
                        'пакет'         => 'warning',
                        'наклейка'      => 'purple',
                        'прибори'       => 'primary',
                        'серветка'      => 'secondary',
                        default         => 'gray',
                    })
                    ->placeholder('Госптовар'),

                TextColumn::make('capacity_label')
                    ->label('Ємність')
                    ->placeholder('—'),

                TextColumn::make('pair.name')
                    ->label('Пара')
                    ->placeholder('—')
                    ->limit(25),

                TextColumn::make('projectData.name')
                    ->label('Проєкт')
                    ->badge()
                    ->color(fn ($record) => $record->projectData?->color ?? 'gray')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('unit')
                    ->label('Од.'),

                TextColumn::make('stock')
                    ->label('Залишок')
                    ->numeric(decimalPlaces: 0)
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'success'),

                TextColumn::make('price')
                    ->label('Ціна (₴)')
                    ->money('UAH')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('packaging_type')
                    ->label('Тип упаковки')
                    ->options(array_merge(['__госптовар' => 'Госптовари'], Packaging::TYPES))
                    ->query(function ($query, array $data) {
                        if (!$data['value']) return $query;
                        if ($data['value'] === '__госптовар') {
                            return $query->whereNull('packaging_type');
                        }
                        return $query->where('packaging_type', $data['value']);
                    }),

                SelectFilter::make('project')
                    ->label('Проєкт')
                    ->options(fn () => Project::where('is_active', true)->pluck('name', 'slug'))
                    ->placeholder('Всі проєкти'),
            ])
            ->actions([
                Actions\EditAction::make()->label('')->tooltip('Змінити'),
                Actions\DeleteAction::make()->label('')->tooltip('Видалити'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackagings::route('/'),
        ];
    }
}
