<?php

namespace App\Filament\Resources\AiAgentResource\RelationManagers;

use App\Models\AiAgentTask;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/** Задачі агенту: ставить людина, агент забирає по API. */
class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';
    protected static ?string $title = 'Задачі';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')->label('Задача')->required()->columnSpanFull(),
            Forms\Components\Textarea::make('details')->label('Деталі')->rows(4)->columnSpanFull(),
            Forms\Components\DateTimePicker::make('due_at')->label('До'),
            Forms\Components\Select::make('status')->label('Статус')->options(AiAgentTask::STATUSES)->default('new'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('title')->label('Задача')->wrap(),
                Tables\Columns\TextColumn::make('status')->label('Статус')->badge()
                    ->formatStateUsing(fn ($state) => AiAgentTask::STATUSES[$state] ?? $state),
                Tables\Columns\TextColumn::make('due_at')->label('До')->dateTime('d.m H:i')->placeholder('—'),
                Tables\Columns\TextColumn::make('result')->label('Результат')->limit(80)->wrap()->placeholder('—'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()->label('Поставити задачу')
                    ->mutateFormDataUsing(fn (array $d) => $d + ['created_by' => auth()->id()]),
            ])
            ->actions([Tables\Actions\EditAction::make()->label(''), Tables\Actions\DeleteAction::make()->label('')]);
    }
}
