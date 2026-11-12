<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GeneratorRefuelResource\Pages;
use App\Models\GeneratorRefuel;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Заправки генератора: літри, ціна, сума, фото колонки, кому оплачено. */
class GeneratorRefuelResource extends Resource
{
    protected static ?string $model = GeneratorRefuel::class;

    protected static ?string $navigationIcon = 'heroicon-o-fire';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?string $navigationLabel = 'Заправки генератора';
    protected static ?string $modelLabel = 'заправку';
    protected static ?string $pluralModelLabel = 'Заправки генератора';
    protected static ?int $navigationSort = 51;

    public static function canAccess(): bool
    {
        return \App\Support\SchemaReady::has('generator_refuels') && (bool) auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        $sum = fn (Get $get, Set $set) => $set('amount', round((float) $get('liters') * (float) $get('price_per_liter'), 2));

        return $form->schema([
            Forms\Components\DatePicker::make('date')->label('Дата заправки')->required()->default(now())->maxDate(now()),
            Forms\Components\TextInput::make('paid_to')->label('Кому оплачено')->maxLength(255),
            Forms\Components\TextInput::make('liters')->label('Літрів')->required()->numeric()->minValue(0.01)
                ->live(onBlur: true)->afterStateUpdated($sum),
            Forms\Components\TextInput::make('price_per_liter')->label('Ціна за літр, ₴')->required()->numeric()->minValue(0.01)
                ->live(onBlur: true)->afterStateUpdated($sum),
            Forms\Components\TextInput::make('amount')->label('Сума, ₴')->required()->numeric()->minValue(0)
                ->helperText('Рахується сама; можна виправити, якщо заплатили округлену суму.'),
            Forms\Components\TextInput::make('engine_hours')->label('Мотогодини генератора')->numeric()->minValue(0),
            Forms\Components\Select::make('transaction_id')->label('Транзакція оплати')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => Transaction::where('type', 'expense')
                    ->where(fn ($q) => $q->where('amount', 'like', "%{$search}%")->orWhere('comment', 'like', "%{$search}%")->orWhere('category', 'like', "%{$search}%"))
                    ->orderByDesc('date')->limit(20)->get()->mapWithKeys(fn (Transaction $t) => [$t->id => self::txLabel($t)])->all())
                ->getOptionLabelUsing(fn ($value) => ($t = Transaction::find($value)) ? self::txLabel($t) : null)
                ->helperText('Пошук за сумою, категорією або коментарем.'),
            Forms\Components\FileUpload::make('photo')->label('Фото лічильника колонки / чека')
                ->image()->directory('generator-refuels')->openable(),
            Forms\Components\TextInput::make('comment')->label('Коментар')->maxLength(255)->columnSpanFull(),
        ]);
    }

    private static function txLabel(Transaction $t): string
    {
        return $t->date?->format('d.m.Y').' · '.number_format(abs((float) $t->amount), 0, '.', ' ').' ₴ · '.($t->category ?: '—')
            .($t->comment ? ' · '.\Illuminate\Support\Str::limit($t->comment, 40) : '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('liters')->label('Літрів')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')),
                Tables\Columns\TextColumn::make('price_per_liter')->label('₴/л'),
                Tables\Columns\TextColumn::make('amount')->label('Сума, ₴')->numeric(0, '.', ' ')->weight('bold')
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->label('Разом')->numeric(0, '.', ' ')),
                Tables\Columns\TextColumn::make('paid_to')->label('Кому оплачено')->wrap(),
                Tables\Columns\IconColumn::make('transaction_id')->label('У касі')->boolean()
                    ->state(fn (GeneratorRefuel $r) => (bool) $r->transaction_id)
                    ->tooltip(fn (GeneratorRefuel $r) => $r->transaction_id ? 'Привʼязано до транзакції #'.$r->transaction_id : 'Оплату ще не привʼязано'),
                Tables\Columns\TextColumn::make('engine_hours')->label('Мотогод.')->toggleable(),
                Tables\Columns\ImageColumn::make('photo')->label('Фото')->height(44),
                Tables\Columns\TextColumn::make('user.name')->label('Вніс')
                    ->description(fn (GeneratorRefuel $r) => $r->created_at?->format('d.m.Y H:i'))->toggleable(),
                Tables\Columns\TextColumn::make('comment')->label('Коментар')->wrap()->toggleable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageGeneratorRefuels::route('/')];
    }
}
