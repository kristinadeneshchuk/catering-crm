<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DietResource\Pages;
use App\Models\Diet;
use App\Traits\RestrictCookAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DietResource extends Resource
{
    use RestrictCookAccess;

    protected static ?string $model = Diet::class;

    // Іконку НЕ задаємо: група «Довідник» має власну, а Filament забороняє
    // мати іконки і в групи, і в її пунктів — інакше падає весь /admin.
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?string $navigationLabel = 'Лікувальні дієти';
    protected static ?string $modelLabel = 'Дієта';
    protected static ?string $pluralModelLabel = 'Лікувальні дієти';
    protected static ?int $navigationSort = 8;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Основне')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('number')
                        ->label('Номер столу')
                        ->required()
                        ->maxLength(8)
                        ->helperText('Напр. 1, 1а, 5'),

                    Forms\Components\TextInput::make('name')
                        ->label('Назва / при чому')
                        ->required()
                        ->columnSpan(2),

                    Forms\Components\Textarea::make('indications')
                        ->label('Показання')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Правила харчування')
                ->description('Це те, що читає ШІ під час підбору індивідуального меню.')
                ->schema([
                    Forms\Components\Textarea::make('forbidden')
                        ->label('❌ Категорично заборонено')
                        ->rows(4)
                        ->helperText('Найважливіше поле. Саме за ним відсіюються страви.'),

                    Forms\Components\Textarea::make('allowed')
                        ->label('✅ Дозволено / рекомендовано')
                        ->rows(4),

                    Forms\Components\Textarea::make('cooking_methods')
                        ->label('Спосіб приготування')
                        ->rows(2),
                ]),

            Forms\Components\Section::make('Інструкції')
                ->schema([
                    Forms\Components\Textarea::make('kitchen_note')
                        ->label('👨‍🍳 Для кухні')
                        ->rows(3)
                        ->helperText('Загальні правила цієї дієти. Потрапляють у План виробництва біля страв клієнта.'),

                    Forms\Components\Textarea::make('reheating_note')
                        ->label('📱 Для клієнта (меню за QR)')
                        ->rows(3)
                        ->helperText('Що клієнт побачить, відсканувавши QR: як розігрівати, що врахувати.'),
                ]),

            Forms\Components\Section::make('Режим')
                ->columns(4)
                ->schema([
                    Forms\Components\TextInput::make('temperature_note')
                        ->label('Температура подачі')
                        ->columnSpan(4),

                    Forms\Components\TextInput::make('meals_per_day')->label('Прийомів на день'),
                    Forms\Components\TextInput::make('salt_limit')->label('Сіль'),
                    Forms\Components\TextInput::make('fluid_limit')->label('Рідина'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Активна')
                        ->default(true),
                ]),

            Forms\Components\Section::make('Перевірка технологом')
                ->schema([
                    Forms\Components\Toggle::make('is_reviewed')
                        ->label('✅ Затверджено технологом')
                        ->helperText('Поки вимкнено — дієта вважається чернеткою з інтернету і позначається в списку.'),

                    Forms\Components\Textarea::make('review_notes')
                        ->label('Де джерела розходяться')
                        ->rows(3)
                        ->helperText('Місця, які потребують рішення технолога.'),

                    Forms\Components\Textarea::make('sources')
                        ->label('Джерела')
                        ->rows(2),
                ])
                ->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('№')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->label('При чому')
                    ->wrap()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_reviewed')
                    ->label('Затверджено')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-exclamation-triangle')
                    ->trueColor('success')
                    ->falseColor('warning'),

                Tables\Columns\TextColumn::make('meals_per_day')
                    ->label('Прийомів')
                    ->visibleFrom('md'),

                Tables\Columns\TextColumn::make('clients_count')
                    ->label('Клієнтів')
                    ->counts('clients')
                    ->badge(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Активна')
                    ->boolean()
                    ->visibleFrom('md'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_reviewed')
                    ->label('Затверджено технологом'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDiets::route('/'),
            'create' => Pages\CreateDiet::route('/create'),
            'edit'   => Pages\EditDiet::route('/{record}/edit'),
        ];
    }
}
