<?php

namespace App\Filament\Resources\EmployeeResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PenaltiesRelationManager extends RelationManager
{
    protected static string $relationship = 'penalties';
    protected static ?string $title = 'Штрафи';
    protected static ?string $modelLabel = 'Штраф';
    protected static ?string $pluralModelLabel = 'Штрафи';
    protected static ?string $icon = 'heroicon-o-exclamation-triangle';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('amount')
                ->label('Сума')
                ->required()
                ->numeric()
                ->minValue(0.01)
                ->prefix('₴'),
            Forms\Components\Textarea::make('reason')
                ->label('Причина')
                ->required()
                ->rows(3)
                ->maxLength(500)
                ->columnSpanFull(),
            Forms\Components\DatePicker::make('date')
                ->label('Дата')
                ->required()
                ->default(now())
                ->displayFormat('d.m.Y')
                ->format('Y-m-d'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')
                    ->label('Дата')
                    ->date('d.m.Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сума')
                    ->formatStateUsing(fn ($state) => '-' . number_format((float) $state, 0, '.', ' ') . ' ₴')
                    ->color('danger')
                    ->sortable(),

                Tables\Columns\TextColumn::make('reason')
                    ->label('Причина')
                    ->wrap()
                    ->limit(80),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Призначив')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->label('Додати штраф')
                    ->modalHeading('Новий штраф')
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['created_by'] = auth()->id();
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->label('')
                    ->tooltip('Редагувати')
                    ->modalHeading('Редагувати штраф'),

                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Скасувати')
                    ->modalHeading('Скасувати штраф?')
                    ->modalDescription('Сума повернеться до балансу співробітника.')
                    ->modalSubmitActionLabel('Так, скасувати'),
            ])
            ->bulkActions([]);
    }
}
