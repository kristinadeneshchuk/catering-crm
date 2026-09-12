<?php

namespace App\Filament\Pages;

use App\Models\Account;
use App\Models\Employee;
use App\Models\PaymentClaim;
use App\Models\Project;
use App\Services\Payments\PaymentClaimService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * «Чекають підтвердження» — усі заяви «гроші є», які ще не стали грошима.
 *
 * Сюди потрапляє все: клієнт написав агенту «оплатив», клієнт каже «передав
 * курʼєру готівкою», курʼєр вписав готівку у звіт зміни, виставили рахунок.
 * Жодна з цих заяв сама не ставить is_paid — лише кнопка «Підтвердити» тут.
 */
class PaymentClaims extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationGroup = 'Система';
    protected static ?int $navigationSort = 3;
    protected static ?string $navigationLabel = 'Чекають підтвердження';
    protected static ?string $title = 'Оплати: чекають підтвердження';
    protected static ?string $slug = 'payment-claims';
    protected static string $view = 'filament.pages.payment-claims';

    public static function canAccess(): bool
    {
        // Поки міграція не пройшла, сторінки для меню не існує.
        return \App\Support\SchemaReady::has('payment_claims')
            && PaymentClaimService::canResolve(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        if (! \App\Support\SchemaReady::has('payment_claims')) {
            return null;
        }

        $count = PaymentClaim::pending()
            ->where('source', '!=', PaymentClaim::SOURCE_INVOICE_SENT)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Коли')
                    ->dateTime('d.m H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('client.name')
                    ->label('Клієнт')
                    ->description(fn (PaymentClaim $c) => 'Замовлення #'.$c->order_id
                        .($c->order?->project ? ' · '.$c->order->project : ''))
                    ->url(fn (PaymentClaim $c) => \App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $c->order_id]))
                    ->searchable(),

                Tables\Columns\TextColumn::make('source')
                    ->label('Джерело')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PaymentClaim::sourceLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PaymentClaim::SOURCE_COURIER_CASH    => 'info',
                        PaymentClaim::SOURCE_CLIENT_CASH     => 'warning',
                        PaymentClaim::SOURCE_CLIENT_TRANSFER => 'success',
                        default                              => 'gray',
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Сума')
                    ->money('UAH', locale: 'uk')
                    ->weight('bold')
                    ->description(fn (PaymentClaim $c) => $this->pairDescription($c)),

                Tables\Columns\TextColumn::make('employee.name')
                    ->label('Курʼєр')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('order_debt')
                    ->label('Борг за замовленням')
                    ->state(fn (PaymentClaim $c) => app(PaymentClaimService::class)->orderPaymentState($c->order)['debt'])
                    ->money('UAH', locale: 'uk')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('attachment_path')
                    ->label('Скрін')
                    ->formatStateUsing(fn () => 'відкрити')
                    ->url(fn (PaymentClaim $c) => $c->attachment_path ? route('payment-claims.attachment', $c) : null, true)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('comment')
                    ->label('Коментар')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PaymentClaim::statusLabels()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        PaymentClaim::STATUS_CONFIRMED  => 'success',
                        PaymentClaim::STATUS_REJECTED   => 'danger',
                        PaymentClaim::STATUS_SUPERSEDED => 'gray',
                        default                         => 'warning',
                    })
                    ->description(fn (PaymentClaim $c) => $c->reject_reason)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Статус')
                    ->options(PaymentClaim::statusLabels())
                    ->default(PaymentClaim::STATUS_PENDING),

                Tables\Filters\SelectFilter::make('source')
                    ->label('Джерело')
                    ->options(PaymentClaim::sourceLabels()),

                Tables\Filters\SelectFilter::make('project')
                    ->label('Бренд')
                    ->options(fn () => Project::where('is_active', true)->orderBy('name')->pluck('name', 'slug'))
                    ->query(fn (Builder $q, array $data) => $data['value']
                        ? $q->whereHas('order', fn ($o) => $o->where('project', $data['value']))
                        : $q),

                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Курʼєр')
                    ->options(fn () => Employee::where('position', 'courier')->orderBy('name')->pluck('name', 'id')),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from')->label('З'),
                        Forms\Components\DatePicker::make('until')->label('По'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),

                Tables\Filters\Filter::make('discrepancy')
                    ->label('Лише розбіжності')
                    ->toggle()
                    // Явний підзапрос, а не whereHas: для звʼязку таблиці з собою
                    // Laravel перейменовує її в підзапиті, і порівняння колонок
                    // тихо порівнювало б рядок сам із собою.
                    ->query(fn (Builder $q) => $q->whereRaw(
                        'exists (select 1 from payment_claims p2
                                 where p2.id = payment_claims.paired_claim_id
                                   and abs(p2.amount - payment_claims.amount) > 0.001)'
                    )),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Підтвердити')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (PaymentClaim $c) => $c->isPending())
                    ->modalHeading(fn (PaymentClaim $c) => 'Підтвердити оплату · замовлення #'.$c->order_id)
                    ->modalDescription(fn (PaymentClaim $c) => $c->hasDiscrepancy()
                        ? new HtmlString('<span style="color:#dc2626;font-weight:600;">Розбіжність: клієнт і курʼєр називають різні суми. Вкажіть ту, яку справді отримали.</span>')
                        : 'Гроші зʼявляться в касі, а замовлення перерахує статус оплати.')
                    ->form(fn (PaymentClaim $c) => [
                        Forms\Components\Select::make('account_id')
                            ->label('Каса')
                            ->helperText($c->isCash()
                                ? 'Готівка від курʼєра — зазвичай «Готівка».'
                                : 'Рахунок ФОП, куди прийшов переказ.')
                            ->options(Account::orderBy('name')->pluck('name', 'id'))
                            ->default($c->isCash() ? Account::where('type', 'cash')->value('id') : null)
                            ->required(),

                        Forms\Components\TextInput::make('amount')
                            ->label('Отримано, грн')
                            ->numeric()
                            ->minValue(0.01)
                            ->default((float) $c->amount)
                            ->helperText('Можна виправити, якщо прийшло не стільки, скільки заявили.')
                            ->required(),
                    ])
                    ->action(function (PaymentClaim $c, array $data) {
                        app(PaymentClaimService::class)->confirm(
                            $c,
                            Account::findOrFail($data['account_id']),
                            auth()->user(),
                            (float) $data['amount'],
                        );

                        Notification::make()->title('Оплату підтверджено')->success()->send();
                    }),

                Tables\Actions\Action::make('reject')
                    ->label('Відхилити')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (PaymentClaim $c) => $c->isPending())
                    ->modalHeading('Відхилити заяву')
                    ->form([
                        Forms\Components\Textarea::make('reject_reason')
                            ->label('Причина')
                            ->helperText('Її отримає агент і делікатно уточнить у клієнта. Наприклад: «не бачимо надходження на 2 600 грн».')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (PaymentClaim $c, array $data) {
                        app(PaymentClaimService::class)->reject($c, auth()->user(), $data['reject_reason']);

                        Notification::make()->title('Заяву відхилено')->warning()->send();
                    }),
            ])
            ->emptyStateHeading('Усе підтверджено')
            ->emptyStateDescription('Нових заяв про оплату немає.');
    }

    /**
     * Пара готівкових заяв — один рядок, а не два: це одні й ті самі гроші.
     * Показуємо ту, що зʼявилась раніше; друга видна в описі суми.
     */
    private function baseQuery(): Builder
    {
        return PaymentClaim::query()
            ->with(['client', 'order', 'employee', 'pairedClaim'])
            ->where(function (Builder $q) {
                $q->whereNull('paired_claim_id')
                    ->orWhereColumn('id', '<', 'paired_claim_id');
            });
    }

    private function pairDescription(PaymentClaim $c): ?HtmlString
    {
        $pair = $c->pairedClaim;

        if (! $pair) {
            return null;
        }

        $who = $pair->source === PaymentClaim::SOURCE_COURIER_CASH ? 'курʼєр' : 'клієнт';
        $sum = number_format((float) $pair->amount, 0, ',', ' ').' ₴';

        if ($c->hasDiscrepancy()) {
            return new HtmlString("<span style=\"color:#dc2626;font-weight:600;\">⚠ {$who}: {$sum} — розбіжність</span>");
        }

        return new HtmlString("<span style=\"color:#16a34a;\">✓ {$who} підтверджує: {$sum}</span>");
    }
}
