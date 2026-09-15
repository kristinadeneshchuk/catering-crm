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
 * Виплати курʼєрам: розклад дня → «ЗП погоджена» з вибором рахунку (ФОП чи
 * готівка) → повідомлення в чат оплат з карткою курʼєра.
 *
 * Погоджує лише адмін (docs/tz-ops-agent.md §5). Щоденні кнопки в Telegram
 * власнику більше не шлемо.
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

        $n = CourierPayout::whereIn('status', [CourierPayout::STATUS_DRAFT, CourierPayout::STATUS_SENT])->count();

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
                Tables\Columns\TextColumn::make('account.name')->label('Рахунок')->placeholder('—')
                    ->description(fn (CourierPayout $r) => $r->paid_amount !== null ? 'виплачено '.number_format((float) $r->paid_amount, 0, ',', ' ').' ₴' : null),
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
            ->headerActions([
                Tables\Actions\Action::make('refresh_day')
                    ->label('Порахувати день')
                    ->icon('heroicon-o-calculator')
                    ->visible(fn () => static::isAdmin())
                    ->form([
                        Forms\Components\DatePicker::make('date')->label('Дата')->required()->native(false)
                            ->displayFormat('d.m.Y')->default(now()->subDay())->maxDate(now()),
                    ])
                    ->action(function (array $data) {
                        $n = app(CourierPayoutService::class)->refreshDay(\Carbon\Carbon::parse($data['date'])->toDateString());
                        Notification::make()->title("Пораховано курʼєрів: {$n}")->success()->send();
                    }),
            ])
            ->actions([
                static::approveAction(),
                Tables\Actions\Action::make('cancel_payment')
                    ->label('Скасувати виплату')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (CourierPayout $r) => $r->status === CourierPayout::STATUS_PAID && $r->account_id && static::isAdmin())
                    ->requiresConfirmation()
                    ->modalDescription('Транзакцію виплати буде видалено, борг повернеться курʼєру, у чаті оплат зʼявиться «Скасовано — не платити».')
                    ->action(function (CourierPayout $r) {
                        app(CourierPayoutService::class)->cancelPayment($r);
                        Notification::make()->title('Виплату скасовано')->warning()->send();
                    }),
                Tables\Actions\EditAction::make()->label('Виправити')
                    ->visible(fn (CourierPayout $r) => ! $r->isLocked()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('approve_many')
                    ->label('ЗП погоджена — вибраним')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn () => static::isAdmin())
                    ->form([static::accountSelect()])
                    ->action(function (\Illuminate\Support\Collection $records, array $data) {
                        $service = app(CourierPayoutService::class);
                        $ok = 0;
                        $errors = [];

                        foreach ($records as $r) {
                            $res = $service->approveAndPay($r, (int) $data['account_id'], null, auth()->id());
                            $res['ok'] ? $ok++ : $errors[] = $r->employee?->name.' '.$r->dateString().': '.$res['error'];
                        }

                        Notification::make()
                            ->title("Погоджено: {$ok}")
                            ->body($errors ? implode("\n", $errors) : null)
                            ->{$errors ? 'warning' : 'success'}()
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public static function accountSelect(): Forms\Components\Select
    {
        return Forms\Components\Select::make('account_id')
            ->label('З якого рахунку платимо')
            ->options(function () {
                $ids = config('ops.payout_account_ids', []);

                return \App\Models\Account::query()
                    ->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))
                    ->orderBy('name')
                    ->pluck('name', 'id');
            })
            ->required()
            ->native(false)
            ->placeholder('ФОП або готівка');
    }

    public static function approveAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('approve')
            ->label('ЗП погоджена')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (CourierPayout $r) => $r->status !== CourierPayout::STATUS_PAID && static::isAdmin())
            ->modalHeading(fn (CourierPayout $r) => 'ЗП · '.$r->employee?->name.' · '.\Carbon\Carbon::parse($r->dateString())->format('d.m'))
            ->modalSubmitActionLabel('Погодити й відправити в чат оплат')
            ->form(fn (CourierPayout $r) => [
                Forms\Components\Placeholder::make('breakdown')
                    ->label('Розклад')
                    ->content(new HtmlString('<pre style="white-space:pre-wrap;font-family:inherit;margin:0;line-height:1.5;">'
                        .app(CourierPayoutService::class)->render($r).'</pre>')),
                static::accountSelect(),
                Forms\Components\TextInput::make('amount')
                    ->label('Сума виплати')
                    ->numeric()
                    ->suffix('₴')
                    ->default(max(0, (float) $r->to_pay))
                    ->helperText((float) $r->cash_on_hand > 0
                        ? 'Готівку на руках '.number_format((float) $r->cash_on_hand, 0, ',', ' ').' ₴ уже віднято. Якщо курʼєр здав її менеджеру — поставте '.number_format((float) $r->total, 0, ',', ' ').' ₴.'
                        : null),
                Forms\Components\TextInput::make('comment')->label('Коментар (необовʼязково)'),
            ])
            ->action(function (CourierPayout $r, array $data) {
                $res = app(CourierPayoutService::class)->approveAndPay(
                    $r, (int) $data['account_id'], (float) $data['amount'], auth()->id(), $data['comment'] ?? null,
                );

                if (! $res['ok']) {
                    Notification::make()->title($res['error'])->danger()->send();

                    return;
                }

                Notification::make()
                    ->title('ЗП погоджено')
                    ->body($res['sent'] ? 'Повідомлення в чаті оплат.' : 'Чат оплат не налаштовано (TELEGRAM_PAYMENTS_CHAT_ID) — повідомлення не відправлено.')
                    ->{$res['sent'] ? 'success' : 'warning'}()
                    ->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCourierPayouts::route('/'),
            'edit'  => Pages\EditCourierPayout::route('/{record}/edit'),
        ];
    }
}
