<?php

namespace App\Filament\Resources\ClientResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class AddressesRelationManager extends RelationManager
{
    protected static string $relationship = 'addresses';
    protected static ?string $title = 'Адреси доставки';
    protected static ?string $modelLabel = 'адресу';
    protected static ?string $pluralModelLabel = 'адреси';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')
                ->label('Назва')
                ->placeholder('Дім, Робота, Дача...')
                ->required()
                ->default('Адреса'),

            Forms\Components\Toggle::make('is_default')
                ->label('Адреса за замовчуванням')
                ->onColor('success')
                ->columnSpanFull(),

            Forms\Components\Select::make('address')
                ->label('Адреса')
                ->searchable()
                ->getSearchResultsUsing(function (string $search) {
                    if (strlen($search) < 3) return [];
                    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                        'q'            => $search . ', Київ',
                        'format'       => 'json',
                        'limit'        => 7,
                        'countrycodes' => 'ua',
                    ]);
                    $response = @file_get_contents($url, false, stream_context_create([
                        'http' => ['header' => "User-Agent: CRM/1.0\r\n"],
                    ]));
                    if (!$response) return [];
                    $results = json_decode($response, true) ?? [];
                    $options = [];
                    foreach ($results as $r) {
                        $label = $r['display_name'] ?? '';
                        $options[$label] = $label;
                    }
                    return $options;
                })
                ->required()
                ->placeholder('Почніть вводити вулицю...')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('address_entrance')
                ->label('Під\'їзд'),

            Forms\Components\TextInput::make('address_apartment')
                ->label('Кв/офіс'),

            Forms\Components\TextInput::make('address_floor')
                ->label('Поверх'),

            Forms\Components\TextInput::make('delivery_comment')
                ->label('Коментар для доставки')
                ->placeholder('Домофон, код...')
                ->columnSpanFull(),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Назва')
                    ->badge()
                    ->color(fn ($record) => $record->is_default ? 'success' : 'gray'),

                Tables\Columns\TextColumn::make('address')
                    ->label('Адреса')
                    ->searchable(),

                Tables\Columns\TextColumn::make('address_entrance')
                    ->label('Під\'їзд'),

                Tables\Columns\TextColumn::make('address_apartment')
                    ->label('Кв/офіс'),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('За замовч.')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Додати адресу'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
