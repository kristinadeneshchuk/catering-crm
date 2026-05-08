<?php

namespace App\Filament\Resources\OrderResource\RelationManagers;

use App\Filament\Resources\ActivityResource;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\OrderDayDish;
use App\Support\ActivityLogTranslator;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

class ActivityLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';
    protected static ?string $title = 'Журнал змін';
    protected static ?string $icon = 'heroicon-o-clipboard-document-list';
    protected static ?string $modelLabel = 'запис';
    protected static ?string $pluralModelLabel = 'записи журналу';

    public function isReadOnly(): bool { return true; }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('summary')
                ->label('')
                ->content(function ($record) {
                    if (!$record) return '—';
                    $event = ActivityLogTranslator::event($record->event);
                    $subject = ActivityLogTranslator::subject($record->subject_type)
                        . ($record->subject_id ? ' #' . $record->subject_id : '');
                    $user = $record->causer?->name ?? 'Система';
                    $when = $record->created_at?->format('d.m.Y H:i:s');
                    return new \Illuminate\Support\HtmlString(
                        "<div><b>{$event}</b> · {$subject}</div>"
                        . "<div style='color:#6b7280'>{$user} · {$when}</div>"
                    );
                })
                ->columnSpanFull(),
            Forms\Components\Placeholder::make('changes')
                ->label('Зміни')
                ->content(fn ($record) => ActivityResource::renderChanges($record))
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Order $owner */
        $owner = $this->getOwnerRecord();

        return $table
            ->query(function () use ($owner): Builder {
                $dayIds  = OrderDay::where('order_id', $owner->id)->pluck('id');
                $dishIds = OrderDayDish::where('order_id', $owner->id)->pluck('id');

                return Activity::query()->where(function (Builder $q) use ($owner, $dayIds, $dishIds) {
                    $q->where(function (Builder $qq) use ($owner) {
                        $qq->where('subject_type', Order::class)
                           ->where('subject_id', $owner->id);
                    });
                    if ($dayIds->isNotEmpty()) {
                        $q->orWhere(function (Builder $qq) use ($dayIds) {
                            $qq->where('subject_type', OrderDay::class)
                               ->whereIn('subject_id', $dayIds);
                        });
                    }
                    if ($dishIds->isNotEmpty()) {
                        $q->orWhere(function (Builder $qq) use ($dishIds) {
                            $qq->where('subject_type', OrderDayDish::class)
                               ->whereIn('subject_id', $dishIds);
                        });
                    }
                });
            })
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Дата')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label('Подія')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ActivityLogTranslator::event($state))
                    ->color(fn ($state) => ActivityLogTranslator::eventColor($state)),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label('Об\'єкт')
                    ->formatStateUsing(fn ($state, $record) => ActivityLogTranslator::subject($state)
                        . ($record->subject_id ? ' #' . $record->subject_id : '')),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label('Користувач')
                    ->default('Система'),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Деталі'),
            ])
            ->bulkActions([]);
    }
}
