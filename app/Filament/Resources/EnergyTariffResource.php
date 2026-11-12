<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EnergyTariffResource\Pages;
use App\Models\EnergyTariff;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/** Тариф на електроенергію з датою початку дії — стара історія не перераховується. */
class EnergyTariffResource extends Resource
{
    protected static ?string $model = EnergyTariff::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?string $navigationLabel = 'Тариф на світло';
    protected static ?string $modelLabel = 'тариф';
    protected static ?string $pluralModelLabel = 'Тариф на світло';
    protected static ?int $navigationSort = 52;

    public static function canAccess(): bool
    {
        return \App\Support\SchemaReady::has('energy_tariffs') && (bool) auth()->user()?->isAdmin();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\DatePicker::make('starts_on')->label('Діє з')->required()->unique(ignoreRecord: true),
            Forms\Components\TextInput::make('price')->label('Ціна, ₴ за кВт·год')->required()->numeric()->minValue(0),
            Forms\Components\TextInput::make('comment')->label('Коментар')->maxLength(255)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('starts_on')->label('Діє з')->date('d.m.Y'),
                Tables\Columns\TextColumn::make('price')->label('₴ за кВт·год'),
                Tables\Columns\TextColumn::make('comment')->label('Коментар'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageEnergyTariffs::route('/')];
    }
}
