<?php

namespace App\Filament\Resources;

use App\Traits\RestrictCookAccess;

use App\Filament\Resources\CalorieRangeResource\Pages;
use App\Models\CalorieRange;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Placeholder;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

class CalorieRangeResource extends Resource
{
    use RestrictCookAccess;
    protected static ?string $model = CalorieRange::class;

    // ГРУПУВАННЯ ТА СОРТУВАННЯ
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 5; // Позиція всередині групи
    protected static ?string $navigationLabel = 'Діапазони калорій';
    protected static ?string $pluralModelLabel = 'Діапазони калорій';
    protected static ?string $modelLabel = 'Діапазон';

    /**
     * 🔒 ЗАХИСТ: Тільки Адмін та Менеджер. 
     * Це приховає розділ від Кухаря.
     */
    public static function canViewAny(): bool
    {
        return auth()->user()->role === 'admin' || auth()->user()->role === 'manager';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Параметри діапазону')
                    ->description('Визначте межі калорійності для цієї групи тарифів. Діапазони не повинні перекриватися — кожне значення калорій має потрапляти рівно в один.')
                    ->schema([
                        Placeholder::make('coverage')
                            ->label('Існуючі діапазони')
                            ->columnSpanFull()
                            ->content(function ($record) {
                                $ranges = CalorieRange::orderBy('min_kcal')
                                    ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                    ->get();

                                if ($ranges->isEmpty()) {
                                    return new HtmlString('<span style="color:#94a3b8;font-style:italic;">Жодного діапазону ще не створено.</span>');
                                }

                                $items = $ranges->map(fn ($r) => "<span style=\"display:inline-block; background:#0f172a; color:#fbbf24; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; margin:2px;\">{$r->name} · {$r->min_kcal}–{$r->max_kcal} ккал</span>")
                                    ->implode('');

                                // Знайти «дірки» між діапазонами
                                $gaps = [];
                                $prev = null;
                                foreach ($ranges as $r) {
                                    if ($prev !== null && $r->min_kcal > $prev + 1) {
                                        $gaps[] = ($prev + 1) . '..' . ($r->min_kcal - 1);
                                    }
                                    $prev = (int) $r->max_kcal;
                                }

                                $gapBlock = '';
                                if (!empty($gaps)) {
                                    $gapBlock = '<div style="margin-top:6px; color:#fb923c; font-size:11px;"><strong>⚠️ Дірки:</strong> ' . implode(', ', $gaps) . ' ккал — клієнти з такими калоріями не потраплять у жодний діапазон.</div>';
                                }

                                return new HtmlString($items . $gapBlock);
                            }),

                        TextInput::make('name')
                            ->label('Назва')
                            ->placeholder('Напр: STRONG 2400-2500')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('min_kcal')
                            ->label('Мінімальна ккал')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->rule(function (Forms\Get $get, ?CalorieRange $record) {
                                return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                    $min = (int) $value;
                                    $max = (int) $get('max_kcal');
                                    if ($max > 0 && $min > $max) {
                                        $fail("Мінімальна ккал ({$min}) не може бути більшою за максимальну ({$max}).");
                                    }
                                };
                            }),

                        TextInput::make('max_kcal')
                            ->label('Максимальна ккал')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->live(onBlur: true)
                            ->rule(function (Forms\Get $get, ?CalorieRange $record) {
                                return function (string $attribute, $value, \Closure $fail) use ($get, $record) {
                                    $min = (int) $get('min_kcal');
                                    $max = (int) $value;
                                    if ($min > 0 && $max < $min) {
                                        $fail("Максимальна ккал ({$max}) не може бути меншою за мінімальну ({$min}).");
                                        return;
                                    }
                                    if ($min <= 0 || $max <= 0) return;

                                    // Перевірка перекриття з існуючими діапазонами
                                    $overlap = CalorieRange::query()
                                        ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
                                        ->where('min_kcal', '<=', $max)
                                        ->where('max_kcal', '>=', $min)
                                        ->first();

                                    if ($overlap) {
                                        $fail("Перекривається з діапазоном «{$overlap->name}» ({$overlap->min_kcal}–{$overlap->max_kcal} ккал).");
                                    }
                                };
                            }),
                    ])->columns(3),

                Placeholder::make('prices_hint')
                    ->label('')
                    ->content(new HtmlString(
                        '<div style="background:#1e293b; border:1px dashed #475569; border-radius:6px; padding:10px 14px; color:#cbd5e1; font-size:12px;">'
                        . '💡 Ціни на цей діапазон для кожного тарифу налаштовуються у <strong>«Категорії тарифів»</strong> — там у формі тарифу є секція «Ціни по діапазонах калорій».'
                        . '<br>Коли ти створюєш новий діапазон — рядок ціни (0₴) автоматично додається у кожний тариф, лишається тільки проставити суми.'
                        . '</div>'
                    )),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Назва діапазону')
                    ->sortable()
                    ->searchable(),
                    
                TextColumn::make('min_kcal')
                    ->label('Мін. ккал')
                    ->sortable(),
                    
                TextColumn::make('max_kcal')
                    ->label('Макс. ккал')
                    ->sortable(),

                // Колонка показує, для скількох тарифів уже встановлена ціна
                TextColumn::make('prices_count')
                    ->label('Налаштовано цін')
                    ->counts('prices')
                    ->badge()
                    ->color('success'),
            ])
            ->filters([
                // Тут можна додати фільтр за межами калорій
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
        return [
            // Якщо матриця цін стане дуже великою, можна винести її в RelationManager
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCalorieRanges::route('/'),
            'create' => Pages\CreateCalorieRange::route('/create'),
            'edit' => Pages\EditCalorieRange::route('/{record}/edit'),
        ];
    }
}