<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    // 🔥 Помещаем в ваш "Довідник"
    protected static ?string $navigationGroup = 'Довідник';
    protected static ?int $navigationSort = 8; 
    protected static ?string $navigationLabel = 'Постачальники';
    protected static ?string $pluralModelLabel = 'Постачальники';
    protected static ?string $modelLabel = 'Постачальник';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Реквізити та Контакти')
                    ->schema([
                        TextInput::make('name')
                            ->label('Назва постачальника (ФОП / ТОВ)')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(), // Растягиваем на всю ширину

                        TextInput::make('inn')
                            ->label('ІПН / ЄДРПОУ')
                            ->maxLength(255),

                        TextInput::make('contact_person')
                            ->label('Контактна особа')
                            ->maxLength(255),

                        TextInput::make('phone')
                            ->label('Номер телефону')
                            ->tel() // Включает формат телефона
                            ->maxLength(255),
                    ])->columns(2) // Делаем два столбца для нижних полей
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->width(50),

                TextColumn::make('name')
                    ->label('Назва')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('contact_person')
                    ->label('Контактна особа')
                    ->searchable(),

                TextColumn::make('phone')
                    ->label('Телефон')
                    ->searchable()
                    ->copyable(), // Добавил возможность копировать по клику

                TextColumn::make('inn')
                    ->label('ІПН / ЄДРПОУ')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true), // Скрыто по умолчанию, можно включить
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                //
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
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}