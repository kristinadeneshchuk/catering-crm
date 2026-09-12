<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourierPayoutResource\Pages;
use App\Models\CourierPayout;
use App\Models\Employee;
use App\Services\Couriers\CourierPayoutService;
use App\Services\Payments\PaymentClaimService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Виплати курʼєрам на погодженні.
 *
 * Сюди веде кнопка ✏️ «Виправити» з Telegram: власник бачить помилку в
 * розкладі, виправляє тут — і повідомлення в обох чатах оновлюється саме.
 */
class CourierPayoutResource extends Resource
{
    protected static ?string $model = CourierPayout::class;

    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Виплати курʼєрам';
    protected static ?string $modelLabel = 'Виплата курʼєру';
    protected static ?string $pluralModelLabel = 'Виплати курʼєрам';

    public static function canAccess(): bool
    {
        return \App\Support\SchemaReady::has('courier_payouts')
            && PaymentClaimService::canResolve(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! \App\Support\SchemaReady::has('courier_payouts')) {
            return null;
        }

        $n = CourierPayout::where('status', CourierPayout::STATUS_SENT)->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('breakdown')
                ->label('Розклад')
                ->content(fn (?CourierPayout $record) => $record
                    ? new HtmlString('<pre style="white-space:pre-wrap;font-family:inherit;margin:0;line-height:1.6;">'
                        .app(CourierPayoutService::class)->render($record).'</pre>')
                    : '—')
                ->columnSpanFull(),

            Forms\Components\Section::make('Пробіг')
                ->description('Зміна тут перераховує компенсацію і рухає баланс курʼєра так само, як сторінка Логістики.')
                ->schema(fn (?CourierPayout $record) => collect($record?->components['mileage'] ?? [])
                    ->map(fn ($m) => Forms\Components\Fieldset::make('mileage_'.$m['slot'])
                        ->label(match ($m['slot']) { 'morning' => 'Ранок', 'evening' => 'Вечір', default => 'Увесь день' })
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make("mileage.{$m['slot']}.start_km")->label('Старт, км')->numeric()->default($m['start_km']),
                            Forms\Components\TextInput::make("mileage.{$m['slot']}.end_km")->label('Фініш, км')->numeric()->default($m['end_km']),
                            Forms\Components\TextInput::make("mileage.{$m['slot']}.fuel_price_per_liter")->label('Пальне, грн/л')->numeric()->default($m['fuel_price']),
                        ]))
                    ->all())
                ->visible(fn (?CourierPayout $record) => ! empty($record?->components['mileage'])),

            Forms\Components\Section::make('Коригування')
                ->description('Плюс — премія, мінус — штраф. Потрапить у «Зарплати» і в баланс курʼєра.')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('adjust_amount')->label('Сума, грн')->numeric()->helperText('Напр. 200 або −150'),
                    Forms\Components\TextInput::make('adjust_reason')->label('За що')->requiredWith('adjust_amount'),
                ]),

            Forms\Components\Textarea::make('comment')
                ->label('Коментар для власника')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                Tables\Columns\TextColumn::make('employee.name')->label('Курʼєр')->searchable(),
                Tables\Columns\TextColumn::make('total')->label('Нараховано')->money('UAH', locale: 'uk'),
                Tables\Columns\TextColumn::make('cash_on_hand')->label('Готівка на руках')->money('UAH', locale: 'uk'),
                Tables\Columns\TextColumn::make('to_pay')->label('До виплати')->money('UAH', locale: 'uk')->weight('bold'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => CourierPayout::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        CourierPayout::STATUS_APPROVED => 'success',
                        CourierPayout::STATUS_PAID     => 'gray',
                        CourierPayout::STATUS_REJECTED => 'danger',
                        CourierPayout::STATUS_SENT     => 'warning',
                        default                        => 'info',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Статус')->options(CourierPayout::statusLabels()),
                Tables\Filters\SelectFilter::make('employee_id')->label('Курʼєр')
                    ->options(fn () => Employee::where('position', 'courier')->orderBy('name')->pluck('name', 'id')),
            ])
            ->actions([
                Tables\Actions\Action::make('send')
                    ->label('На погодження')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (CourierPayout $r) => in_array($r->status, [CourierPayout::STATUS_DRAFT, CourierPayout::STATUS_REJECTED], true))
                    ->requiresConfirmation()
                    ->action(function (CourierPayout $r) {
                        $service = app(CourierPayoutService::class);
                        $service->sendForApproval($service->refresh($r->employee, $r->dateString()));
                        Notification::make()->title('Надіслано власнику')->success()->send();
                    }),
                Tables\Actions\EditAction::make()->label('Виправити')
                    ->visible(fn (CourierPayout $r) => ! $r->isLocked()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourierPayouts::route('/'),
            'edit'  => Pages\EditCourierPayout::route('/{record}/edit'),
        ];
    }
}
