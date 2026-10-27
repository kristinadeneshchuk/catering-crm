<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankReconciliationResource\Pages;
use App\Models\Account;
use App\Models\BankOperation;
use App\Models\Employee;
use App\Models\Order;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Transaction;
use App\Services\Accounting\BankReconciler;
use App\Services\Accounting\CashBook;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Звірка» — те, що раніше робили в Saldo: кожну операцію банку розписати
 * (оплата клієнта, накладна, ЗП, витрата за статтею) або позначити «не стосується».
 * Гроші бізнесу — лише супер адмін (бухгалтер = супер адмін).
 */
class BankReconciliationResource extends Resource
{
    protected static ?string $model = BankOperation::class;
    protected static ?string $slug = 'reconcile';

    protected static ?string $navigationIcon = 'heroicon-o-check-badge';
    protected static ?string $navigationLabel = 'Звірка';
    protected static ?string $modelLabel = 'Операція банку';
    protected static ?string $pluralModelLabel = 'Звірка';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?int $navigationSort = 45;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canViewAny(): bool { return static::canAccess(); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }
        $n = BankReconciler::queue()->whereNull('reconcile_status')->count();

        return $n ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getEloquentQuery(): Builder
    {
        return BankReconciler::queue()->with(['account', 'transactions.order.client', 'transactions.employee', 'reconciledBy']);
    }

    public static function reconciler(): BankReconciler
    {
        return app(BankReconciler::class);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('operated_at', 'desc')
            ->columns([
                TextColumn::make('operated_at')->label('Дата')->dateTime('d.m H:i')->sortable(),
                TextColumn::make('account.name')->label('Рахунок')->badge()->color('gray'),
                TextColumn::make('amount')->label('Сума')->money('UAH')->weight('bold')->sortable()
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger'),
                TextColumn::make('counter_name')->label('Контрагент')
                    ->description(fn (BankOperation $r) => mb_strimwidth(trim($r->description.' '.$r->comment), 0, 90, '…'))
                    ->searchable(['counter_name', 'description', 'comment'])
                    ->wrap(),
                TextColumn::make('what')->label('Що це')
                    ->state(fn (BankOperation $r) => static::what($r))
                    ->color(fn (BankOperation $r) => $r->reconcile_status ? null : 'gray')
                    ->icon(fn (BankOperation $r) => match ($r->reconcile_status) {
                        BankOperation::MATCHED => 'heroicon-o-check-circle',
                        BankOperation::IGNORED => 'heroicon-o-minus-circle',
                        default                => static::reconciler()->suggest($r) ? 'heroicon-o-light-bulb' : 'heroicon-o-question-mark-circle',
                    })
                    ->iconColor(fn (BankOperation $r) => match ($r->reconcile_status) {
                        BankOperation::MATCHED => 'success',
                        BankOperation::IGNORED => 'gray',
                        default                => 'warning',
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options(['todo' => 'Нерозібрані', 'matched' => 'Розібрані', 'ignored' => 'Не стосується'])
                    ->default('todo')
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'todo'    => $query->whereNull('reconcile_status'),
                        'matched' => $query->where('reconcile_status', BankOperation::MATCHED),
                        'ignored' => $query->where('reconcile_status', BankOperation::IGNORED),
                        default   => $query,
                    }),
                SelectFilter::make('account_id')->label('Рахунок')
                    ->options(fn () => Account::whereIn('id', BankReconciler::accountIds())->pluck('name', 'id')),
                SelectFilter::make('direction')->label('Напрям')
                    ->options(['in' => 'Надходження', 'out' => 'Списання'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'in'    => $query->where('amount', '>', 0),
                        'out'   => $query->where('amount', '<', 0),
                        default => $query,
                    }),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(3)
            ->actions([
                Action::make('accept')
                    ->label('Прийняти')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->button()
                    ->visible(fn (BankOperation $r) => ! $r->reconcile_status && static::reconciler()->suggest($r))
                    ->action(function (BankOperation $r) {
                        static::reconciler()->accept($r, auth()->id());
                        Notification::make()->title('Розібрано')->success()->send();
                    }),
                Tables\Actions\ActionGroup::make([
                    static::clientAction(),
                    static::invoicesAction(),
                    static::salaryAction(),
                    static::categoryAction(),
                    static::linkAction(),
                    static::ignoreAction(),
                ])->label('Розібрати')->icon('heroicon-o-pencil-square')->button()->color('gray')
                    ->visible(fn (BankOperation $r) => ! $r->reconcile_status),
                Action::make('reset')
                    ->label('')
                    ->tooltip('Скасувати розбір')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Записи, створені «Звіркою», буде видалено (накладна знову стане неоплаченою); раніше внесені — лише відвʼяжуться.')
                    ->visible(fn (BankOperation $r) => (bool) $r->reconcile_status)
                    ->action(fn (BankOperation $r) => static::reconciler()->reset($r)),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('acceptAll')
                    ->label('Прийняти пропозиції')
                    ->icon('heroicon-o-check')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records) {
                        $n = $records->filter(fn (BankOperation $r) => static::reconciler()->accept($r, auth()->id()))->count();
                        Notification::make()->title("Розібрано: $n")->success()->send();
                    }),
            ])
            ->paginated([25, 50, 100])
            ->defaultPaginationPageOption(50);
    }

    /** Що це: розібране — чим саме, нерозібране — пропозиція CRM. */
    public static function what(BankOperation $r): string
    {
        if ($r->reconcile_status === BankOperation::IGNORED) {
            return (string) $r->reconcile_note;
        }
        if ($r->reconcile_status === BankOperation::MATCHED) {
            return $r->transactions->map(fn (Transaction $t) => match (true) {
                (bool) $t->order_id          => 'Оплата · '.($t->order?->client?->name ?? '').' #'.$t->order_id,
                (bool) $t->stock_document_id => 'Накладна #'.$t->stock_document_id,
                (bool) $t->employee_id       => 'ЗП · '.($t->employee?->name ?? ''),
                default                      => (string) $t->category,
            })->implode(', ') ?: 'Розібрано';
        }
        $s = static::reconciler()->suggest($r);

        return $s ? 'Пропозиція: '.$s['label'] : 'Не розібрано';
    }

    // ---- дії розбору ----

    private static function remember(): Forms\Components\Toggle
    {
        return Forms\Components\Toggle::make('remember')->label('Запамʼятати контрагента — далі розбирати так само')->default(true);
    }

    private static function clientAction(): Action
    {
        return Action::make('client')
            ->label('Оплата клієнта')
            ->icon('heroicon-o-user')
            ->visible(fn (BankOperation $r) => $r->isIncoming())
            ->form(fn (BankOperation $r) => [
                Forms\Components\Select::make('order_id')
                    ->label('Замовлення')
                    ->searchable()
                    ->default(fn () => static::reconciler()->orderIds(trim($r->description.' '.$r->comment))[0] ?? null)
                    ->getSearchResultsUsing(fn (string $search) => CashOperationResource::searchOrders($search))
                    ->getOptionLabelUsing(fn ($value) => CashOperationResource::orderLabel(Order::with('client')->find($value)))
                    ->required(),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->clientPayment($r, (int) $data['order_id'], auth()->id());
                Notification::make()->title('Оплату зараховано клієнту')->success()->send();
            });
    }

    private static function invoicesAction(): Action
    {
        return Action::make('invoices')
            ->label('Оплата накладних')
            ->icon('heroicon-o-document-text')
            ->visible(fn (BankOperation $r) => ! $r->isIncoming())
            ->form(fn (BankOperation $r) => [
                Forms\Components\Select::make('ids')
                    ->label('Неоплачені накладні')
                    ->multiple()
                    ->options(function () use ($r) {
                        $suppliers = $r->counter_edrpou ? Supplier::where('inn', $r->counter_edrpou)->pluck('id') : collect();

                        return StockDocument::query()->posted()->where('type', 'receipt')->where('is_paid', false)
                            ->when($suppliers->isNotEmpty(), fn ($q) => $q->whereIn('supplier_id', $suppliers))
                            ->with('supplier')->orderByDesc('operation_date')->limit(200)->get()
                            ->mapWithKeys(fn (StockDocument $d) => [$d->id => '#'.$d->id.' · '.$d->operation_date?->format('d.m')
                                .' · '.($d->supplier?->name ?? '—').' · '.number_format((float) $d->total_sum, 2, ',', ' ').' ₴']);
                    })
                    ->helperText(fn () => 'Сума платежу: '.number_format(abs((float) $r->amount), 2, ',', ' ').' ₴')
                    ->required(),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->payInvoices($r, array_map('intval', $data['ids']), auth()->id());
                Notification::make()->title('Накладні оплачено')->success()->send();
            });
    }

    private static function salaryAction(): Action
    {
        return Action::make('salary')
            ->label('Виплата ЗП')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (BankOperation $r) => ! $r->isIncoming())
            ->form([
                Forms\Components\Select::make('employee_id')->label('Співробітник')->searchable()
                    ->options(fn () => Employee::orderBy('name')->pluck('name', 'id'))->required(),
                static::remember(),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->record($r, CashBook::SALARY, (int) $data['employee_id'], (bool) $data['remember'], auth()->id());
                Notification::make()->title('Виплату записано')->success()->send();
            });
    }

    private static function categoryAction(): Action
    {
        return Action::make('category')
            ->label('Стаття')
            ->icon('heroicon-o-tag')
            ->form(fn (BankOperation $r) => [
                Forms\Components\Select::make('category')->label('Стаття')->required()
                    ->options($r->isIncoming()
                        ? array_combine($in = array_values(array_diff(CashBook::INCOMES, [CashBook::CLIENT])), $in)
                        : array_combine($out = array_merge(CashBook::PNL_EXPENSES, array_keys(CashBook::OTHER_EXPENSES)), $out)),
                static::remember(),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->record($r, $data['category'], null, (bool) $data['remember'], auth()->id());
                Notification::make()->title('Записано')->success()->send();
            });
    }

    private static function linkAction(): Action
    {
        return Action::make('link')
            ->label('Вже є в CRM')
            ->icon('heroicon-o-link')
            ->form(fn (BankOperation $r) => [
                Forms\Components\Select::make('ids')->label('Записи CRM на цьому рахунку (±7 днів)')->multiple()->required()
                    ->options(fn () => Transaction::query()
                        ->where('account_id', $r->account_id)
                        ->where('type', $r->isIncoming() ? '=' : '!=', 'income')
                        ->whereBetween('date', [$r->operated_at->copy()->subDays(7)->toDateString(), $r->operated_at->copy()->addDays(7)->toDateString()])
                        ->whereNotIn('id', \Illuminate\Support\Facades\DB::table('bank_operation_transaction')->select('transaction_id'))
                        ->with('order.client')->orderBy('date')->get()
                        ->mapWithKeys(fn (Transaction $t) => [$t->id => $t->date->format('d.m').' · '.number_format((float) $t->amount, 2, ',', ' ').' ₴ · '
                            .($t->order_id ? ($t->order?->client?->name ?? '').' #'.$t->order_id : ($t->category ?? '—'))])),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->link($r, array_map('intval', $data['ids']), auth()->id());
                Notification::make()->title('Привʼязано')->success()->send();
            });
    }

    private static function ignoreAction(): Action
    {
        return Action::make('ignore')
            ->label('Не стосується')
            ->icon('heroicon-o-minus-circle')
            ->form([
                Forms\Components\TextInput::make('note')->label('Чому')->placeholder('Особисте, повернення, помилковий платіж…'),
                static::remember()->default(false),
            ])
            ->action(function (BankOperation $r, array $data) {
                static::reconciler()->ignore($r, $data['note'] ?? null, (bool) $data['remember'], auth()->id());
                Notification::make()->title('Позначено')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankReconciliation::route('/'),
        ];
    }
}
