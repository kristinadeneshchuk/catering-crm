<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PortionGridResource\Pages;
use App\Models\MealType;
use App\Models\PortionGrid;
use App\Traits\RestrictCookAccess;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Сітка тарифів другої версії фасування.
 *
 * Один рядок — один тариф: які прийоми входять і якого розміру. Усе
 * редагується тут, у коді нічого міняти не треба.
 */
class PortionGridResource extends Resource
{
    use RestrictCookAccess;

    protected static ?string $model = PortionGrid::class;

    protected static ?string $navigationIcon  = 'heroicon-o-squares-2x2';
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?string $navigationLabel = 'Сітка порцій';
    protected static ?string $modelLabel      = 'Тариф сітки';
    protected static ?string $pluralModelLabel = 'Сітка порцій';
    protected static ?int    $navigationSort  = 7;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Тариф')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('calories')
                        ->label('Калорійність')
                        ->numeric()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->suffix('ккал')
                        ->live(onBlur: true),

                    Forms\Components\ColorPicker::make('color')
                        ->label('Колір пакета')
                        ->helperText('Збірка замовлення за кольором, без читання тексту.'),

                    Forms\Components\TextInput::make('color_label')
                        ->label('Назва кольору')
                        ->placeholder('Жовтий'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Активний')
                        ->default(true)
                        ->inline(false),
                ]),

            Forms\Components\Section::make('Прийоми їжі')
                ->description('Додайте лише ті прийоми, які входять у тариф. Розмір бокса береться з довідника прийомів.')
                ->schema([
                    Forms\Components\Repeater::make('slots')
                        ->label('')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('meal_type_id')
                                ->label('Прийом')
                                ->options(fn () => MealType::whereNotNull('box_kcal_std')
                                    ->orderBy('sort_order')->pluck('name', 'id'))
                                ->required()
                                ->distinct()
                                ->live(),

                            Forms\Components\Select::make('size')
                                ->label('Розмір')
                                ->options([
                                    PortionGrid::SIZE_STD   => 'Звичайна (Std)',
                                    PortionGrid::SIZE_LARGE => 'Велика (L)',
                                ])
                                ->default(PortionGrid::SIZE_STD)
                                ->required()
                                ->live(),

                            Forms\Components\Placeholder::make('kcal')
                                ->label('Енергія бокса')
                                ->content(function (Forms\Get $get) {
                                    $meal = MealType::find($get('meal_type_id'));

                                    if (! $meal) {
                                        return '—';
                                    }

                                    $kcal = $get('size') === PortionGrid::SIZE_LARGE
                                        ? $meal->box_kcal_large
                                        : $meal->box_kcal_std;

                                    return $kcal ? $kcal.' ккал' : 'не задано';
                                }),
                        ])
                        ->columns(3)
                        ->defaultItems(0)
                        ->reorderable(false)
                        ->live(),
                ]),

            Forms\Components\Section::make('Додаткові снеки')
                ->description('Друга порція перекусу дня: та сама страва, та сама вага, більше штук.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('extra_snacks_std')
                        ->label('Звичайних снеків')
                        ->numeric()->default(0)->minValue(0)->live(onBlur: true),

                    Forms\Components\TextInput::make('extra_snacks_large')
                        ->label('Великих снеків')
                        ->numeric()->default(0)->minValue(0)->live(onBlur: true),
                ]),

            // Головна перевірка: сітка має сходитись у нуль. Помилку тут
            // краще побачити зараз, ніж у виробництві.
            Forms\Components\Placeholder::make('balance')
                ->label('Перевірка')
                ->columnSpanFull()
                ->content(function (Forms\Get $get) {
                    $target = (int) $get('calories');
                    $slots  = collect($get('slots') ?? []);

                    if (! $target || $slots->isEmpty()) {
                        return new \Illuminate\Support\HtmlString(
                            '<span style="color:#9ca3af;">Заповніть калорійність і прийоми.</span>'
                        );
                    }

                    $meals = MealType::whereIn('id', $slots->pluck('meal_type_id')->filter())->get()->keyBy('id');

                    $sum = 0;
                    foreach ($slots as $s) {
                        $meal = $meals[$s['meal_type_id'] ?? null] ?? null;
                        if (! $meal) continue;
                        $sum += (int) (($s['size'] ?? 'std') === PortionGrid::SIZE_LARGE
                            ? $meal->box_kcal_large : $meal->box_kcal_std);
                    }

                    // Додатковий снек — найлегший бокс у тарифі.
                    $snack = $meals->filter(fn ($m) => $m->box_kcal_std)->sortBy('box_kcal_std')->first();
                    if ($snack) {
                        $sum += (int) $get('extra_snacks_std') * (int) $snack->box_kcal_std;
                        $sum += (int) $get('extra_snacks_large') * (int) $snack->box_kcal_large;
                    }

                    $diff = $sum - $target;

                    if ($diff === 0) {
                        return new \Illuminate\Support\HtmlString(
                            "<span style='color:#16a34a;font-weight:700;'>Сходиться: {$sum} ккал</span>"
                        );
                    }

                    $sign = $diff > 0 ? '+' : '';

                    return new \Illuminate\Support\HtmlString(
                        "<span style='color:#dc2626;font-weight:700;'>Не сходиться: {$sum} ккал замість {$target} ({$sign}{$diff})</span>"
                    );
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('calories')
            ->columns([
                Tables\Columns\ColorColumn::make('color')->label(''),

                Tables\Columns\TextColumn::make('calories')
                    ->label('Тариф')
                    ->suffix(' ккал')
                    ->weight('bold')
                    ->sortable(),

                Tables\Columns\TextColumn::make('color_label')->label('Колір'),

                Tables\Columns\TextColumn::make('slots_summary')
                    ->label('Склад')
                    ->getStateUsing(fn (PortionGrid $r) => $r->slots
                        ->sortBy(fn ($s) => $s->mealType?->sort_order ?? 99)
                        ->map(fn ($s) => ($s->mealType?->name ?? '?').' '.$s->sizeLabel())
                        ->implode(' · ')),

                Tables\Columns\TextColumn::make('boxes')
                    ->label('Боксів')
                    ->getStateUsing(fn (PortionGrid $r) => $r->slots->count()
                        + $r->extra_snacks_std + $r->extra_snacks_large),

                Tables\Columns\TextColumn::make('balance')
                    ->label('Перевірка')
                    ->badge()
                    ->getStateUsing(fn (PortionGrid $r) => $r->isBalanced()
                        ? 'сходиться'
                        : $r->actualKcal().' ≠ '.$r->calories)
                    ->color(fn (PortionGrid $r) => $r->isBalanced() ? 'success' : 'danger'),

                Tables\Columns\IconColumn::make('is_active')->label('Активний')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with('slots.mealType');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPortionGrids::route('/'),
            'create' => Pages\CreatePortionGrid::route('/create'),
            'edit'   => Pages\EditPortionGrid::route('/{record}/edit'),
        ];
    }
}
