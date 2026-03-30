<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MealPlanResource\Pages;
use App\Models\MealPlan;
use App\Models\MealType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Grid;
use Illuminate\Support\HtmlString;

class MealPlanResource extends Resource
{
    protected static ?string $model = MealPlan::class;
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Плани харчування';
    protected static ?string $pluralModelLabel = 'Плани харчування';
    protected static ?string $modelLabel = 'План харчування';

    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        $mealTypes = MealType::orderBy('sort_order')->get();

        return $form->schema([
            Section::make('Параметри плану')
                ->schema([
                    TextInput::make('name')
                        ->label('Назва')
                        ->placeholder('Наприклад: Стандарт · 5 страв')
                        ->required()
                        ->maxLength(100)
                        ->columnSpanFull(),

                    TextInput::make('min_kcal')
                        ->label('Калорійність від')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->suffix('ккал'),

                    TextInput::make('max_kcal')
                        ->label('Калорійність до')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->suffix('ккал'),

                    Placeholder::make('_range_hint')
                        ->label('')
                        ->columnSpanFull()
                        ->content(new HtmlString(
                            "<div style='display:flex;align-items:center;gap:8px;padding:8px 12px;" .
                            "background:rgba(96,165,250,0.07);border:1px solid rgba(96,165,250,0.2);border-radius:8px;'>" .
                            "<span style='font-size:16px;'>💡</span>" .
                            "<span style='font-size:12px;color:#94a3b8;'>" .
                            "Клієнти з калорійністю у цьому діапазоні отримуватимуть страви тільки з обраних прийомів їжі." .
                            "</span></div>"
                        )),
                ])
                ->columns(2),

            Section::make('Прийоми їжі')
                ->description('Оберіть які прийоми їжі входять до цього плану')
                ->schema([
                    Placeholder::make('mealTypesSelector')
                        ->label('')
                        ->columnSpanFull()
                        ->content(function () use ($mealTypes) {
                            $html = "<div style='display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:12px;'>";
                            foreach ($mealTypes as $mt) {
                                $html .=
                                    "<div style='background:#0f172a;border:1px solid #1e293b;border-radius:10px;padding:10px 14px;'>" .
                                    "<div style='font-size:13px;font-weight:700;color:#e2e8f0;'>{$mt->name}</div>" .
                                    "<div style='font-size:11px;color:#64748b;margin-top:3px;'>{$mt->energy_percent}% від денного раціону</div>" .
                                    "</div>";
                            }
                            $html .= "</div>";
                            $html .= "<div style='font-size:11px;color:#475569;'>Поставте галочки нижче щоб увімкнути потрібні прийоми:</div>";
                            return new HtmlString($html);
                        }),

                    Forms\Components\CheckboxList::make('mealTypes')
                        ->label('')
                        ->relationship('mealTypes', 'name')
                        ->getOptionLabelFromRecordUsing(fn (MealType $record) =>
                            $record->name . '  ·  ' . $record->energy_percent . '% від раціону'
                        )
                        ->columns(3)
                        ->gridDirection('row')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Назва плану')
                    ->weight('bold')
                    ->searchable()
                    ->grow(false)
                    ->width('220px'),

                Tables\Columns\TextColumn::make('min_kcal')
                    ->label('Калорійність')
                    ->formatStateUsing(fn ($state, MealPlan $record) =>
                        "{$record->min_kcal} – {$record->max_kcal} ккал"
                    )
                    ->badge()
                    ->color('gray')
                    ->grow(false),

                Tables\Columns\TextColumn::make('mealTypes.name')
                    ->label('Прийоми їжі')
                    ->badge()
                    ->separator(',')
                    ->color('info'),

                Tables\Columns\TextColumn::make('mealTypes_count')
                    ->label('Страв')
                    ->counts('mealTypes')
                    ->badge()
                    ->color('success')
                    ->alignCenter()
                    ->grow(false),
            ])
            ->defaultSort('min_kcal', 'asc')
            ->striped()
            ->actions([
                Tables\Actions\EditAction::make()->iconButton(),
                Tables\Actions\DeleteAction::make()->iconButton(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMealPlans::route('/'),
            'create' => Pages\CreateMealPlan::route('/create'),
            'edit'   => Pages\EditMealPlan::route('/{record}/edit'),
        ];
    }
}
