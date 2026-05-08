<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use App\Support\ActivityLogTranslator;
use App\Traits\RestrictCookAccess;
use Filament\Forms\Form;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Spatie\Activitylog\Models\Activity;

class ActivityResource extends Resource
{
    use RestrictCookAccess;

    protected static ?string $model = Activity::class;
    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';
    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 5;
    protected static ?string $navigationLabel = 'Журнал дій';
    protected static ?string $modelLabel = 'запис журналу';
    protected static ?string $pluralModelLabel = 'Журнал дій';

    public static function canCreate(): bool { return false; }
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return false; }

    public static function form(Form $form): Form
    {
        // Форма для read-only перегляду в модалці
        return $form->schema([
            Forms\Components\TextInput::make('event_label')
                ->label('Подія')
                ->formatStateUsing(fn ($record) => ActivityLogTranslator::event($record?->event))
                ->disabled(),
            Forms\Components\TextInput::make('subject_label')
                ->label('Об\'єкт')
                ->formatStateUsing(fn ($record) => ActivityLogTranslator::subject($record?->subject_type)
                    . ($record?->subject_id ? ' #' . $record->subject_id : ''))
                ->disabled(),
            Forms\Components\TextInput::make('causer.name')
                ->label('Користувач')
                ->formatStateUsing(fn ($record) => $record?->causer?->name ?? 'Система')
                ->disabled(),
            Forms\Components\TextInput::make('created_at')
                ->label('Дата')
                ->formatStateUsing(fn ($record) => $record?->created_at?->format('d.m.Y H:i:s'))
                ->disabled(),
            Forms\Components\Placeholder::make('changes')
                ->label('Зміни')
                ->content(fn ($record) => static::renderChanges($record))
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
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
                    ->default('Система')
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Опис')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label('Тип об\'єкта')
                    ->options(ActivityLogTranslator::SUBJECT_LABELS),
                Tables\Filters\SelectFilter::make('event')
                    ->label('Подія')
                    ->options(ActivityLogTranslator::EVENT_LABELS),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Деталі'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
            'view'  => Pages\ViewActivity::route('/{record}'),
        ];
    }

    public static function renderChanges($record): \Illuminate\Contracts\Support\Htmlable
    {
        if (!$record) {
            return new \Illuminate\Support\HtmlString('—');
        }

        $rows = ActivityLogTranslator::changes($record->properties->toArray());
        if (empty($rows)) {
            return new \Illuminate\Support\HtmlString('<em>Без змін полів</em>');
        }

        $html = '<table style="width:100%;border-collapse:collapse;font-size:13px">';
        $html .= '<thead><tr style="background:#f3f4f6">'
            . '<th style="text-align:left;padding:6px;border:1px solid #e5e7eb">Поле</th>'
            . '<th style="text-align:left;padding:6px;border:1px solid #e5e7eb">Було</th>'
            . '<th style="text-align:left;padding:6px;border:1px solid #e5e7eb">Стало</th>'
            . '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr>'
                . '<td style="padding:6px;border:1px solid #e5e7eb;font-weight:500">' . e($row['label']) . '</td>'
                . '<td style="padding:6px;border:1px solid #e5e7eb;color:#991b1b">' . e($row['old']) . '</td>'
                . '<td style="padding:6px;border:1px solid #e5e7eb;color:#065f46">' . e($row['new']) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table>';

        return new \Illuminate\Support\HtmlString($html);
    }
}
