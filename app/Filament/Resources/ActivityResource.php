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
        $dash = '<span style="color:rgba(125,125,125,.7)">—</span>';

        if (!$record) {
            return new \Illuminate\Support\HtmlString($dash);
        }

        $rows = ActivityLogTranslator::changes($record->properties->toArray());
        if (empty($rows)) {
            return new \Illuminate\Support\HtmlString(
                '<em style="color:rgba(125,125,125,.7)">Без змін полів</em>'
            );
        }

        $th    = 'padding:10px 16px;text-align:left;font-size:11px;font-weight:600;'
               . 'text-transform:uppercase;letter-spacing:.06em;color:rgba(125,125,125,1);';
        $tdKey = 'padding:12px 16px;font-size:13px;font-weight:500;color:inherit;'
               . 'border-top:1px solid rgba(125,125,125,.18);width:35%;';
        $tdVal = 'padding:12px 16px;font-size:13px;'
               . 'border-top:1px solid rgba(125,125,125,.18);';
        $box   = 'overflow:hidden;border-radius:10px;border:1px solid rgba(125,125,125,.2);';
        $tbl   = 'width:100%;border-collapse:collapse;font-family:inherit;';
        $pillOld = 'display:inline-flex;align-items:center;padding:3px 10px;border-radius:8px;'
                 . 'background:rgba(244,63,94,.14);color:rgb(244,63,94);font-weight:500;';
        $pillNew = 'display:inline-flex;align-items:center;padding:3px 10px;border-radius:8px;'
                 . 'background:rgba(34,197,94,.14);color:rgb(34,197,94);font-weight:500;';

        $html  = '<div style="' . $box . '">';
        $html .= '<table style="' . $tbl . '">';
        $html .= '<thead><tr>'
              . '<th style="' . $th . '">Поле</th>'
              . '<th style="' . $th . '">Було</th>'
              . '<th style="' . $th . '">Стало</th>'
              . '</tr></thead>';
        $html .= '<tbody>';

        foreach ($rows as $row) {
            $oldEmpty = $row['old'] === '—';
            $newEmpty = $row['new'] === '—';

            $html .= '<tr>';
            $html .= '<td style="' . $tdKey . '">' . e($row['label']) . '</td>';
            $html .= '<td style="' . $tdVal . '">'
                  . ($oldEmpty ? $dash : '<span style="' . $pillOld . '">' . e($row['old']) . '</span>')
                  . '</td>';
            $html .= '<td style="' . $tdVal . '">'
                  . ($newEmpty ? $dash : '<span style="' . $pillNew . '">' . e($row['new']) . '</span>')
                  . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';

        return new \Illuminate\Support\HtmlString($html);
    }
}
