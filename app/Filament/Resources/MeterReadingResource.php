<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MeterReadingResource\Pages;
use App\Filament\Resources\MeterReadingResource\Widgets\EnergySummaryTable;
use App\Models\MeterReading;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Журнал показників лічильника електроенергії: що на лічильнику, коли знято,
 * фото, хто вніс. Спожите й суму рахуємо самі — від попереднього показника.
 */
class MeterReadingResource extends Resource
{
    protected static ?string $model = MeterReading::class;

    protected static ?string $navigationIcon = 'heroicon-o-bolt';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?string $navigationLabel = 'Лічильник світла';
    protected static ?string $modelLabel = 'показник лічильника';
    protected static ?string $pluralModelLabel = 'Лічильник світла';
    protected static ?int $navigationSort = 50;

    public static function canAccess(): bool
    {
        return \App\Support\SchemaReady::has('meter_readings') && (bool) auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('taken_on')->label('Дата зняття показника')
                ->required()->default(now())->maxDate(now())->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('reading')->label('Показник, кВт·год')
                ->required()->integer()->minValue(0),
            Forms\Components\FileUpload::make('photo')->label('Фото лічильника')
                ->image()->directory('meter-readings')->openable()
                ->required(fn (string $operation) => $operation === 'create')->columnSpanFull(),
            Forms\Components\Toggle::make('date_confirmed')->label('Дата підтверджена')->default(true),
            Forms\Components\TextInput::make('comment')->label('Коментар')->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('taken_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('taken_on')->label('Знято')->date('d.m.Y')->sortable()
                    ->description(fn (MeterReading $r) => $r->date_confirmed ? null : 'дата не підтверджена')
                    ->color(fn (MeterReading $r) => $r->date_confirmed ? null : 'warning'),
                Tables\Columns\TextColumn::make('reading')->label('Показник')->numeric(0, '.', ' ')->weight('bold'),
                Tables\Columns\TextColumn::make('kwh')->label('Спожито, кВт')
                    ->state(fn (MeterReading $r) => ($p = $r->period()) ? number_format($p['kwh'], 0, '.', ' ') : '—')
                    ->description(fn (MeterReading $r) => ($p = $r->period()) ? 'з '.$p['from']->format('d.m').', '.$p['days'].' дн.' : null),
                Tables\Columns\TextColumn::make('per_day')->label('кВт/день')
                    ->state(fn (MeterReading $r) => $r->period()['per_day'] ?? '—'),
                Tables\Columns\TextColumn::make('cost')->label('Сума, ₴')
                    ->state(fn (MeterReading $r) => ($p = $r->period()) ? number_format($p['cost'], 0, '.', ' ') : '—')
                    ->description(fn (MeterReading $r) => ($p = $r->period()) ? '× '.rtrim(rtrim(number_format($p['price'], 2, '.', ''), '0'), '.').' ₴' : null),
                Tables\Columns\ImageColumn::make('photo')->label('Фото')->height(44),
                Tables\Columns\TextColumn::make('user.name')->label('Вніс')
                    ->description(fn (MeterReading $r) => $r->created_at?->format('d.m.Y H:i')),
                Tables\Columns\TextColumn::make('comment')->label('Коментар')->wrap()->toggleable(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()])
            ->paginated([25, 50]);
    }

    public static function getWidgets(): array
    {
        return [EnergySummaryTable::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageMeterReadings::route('/')];
    }
}
