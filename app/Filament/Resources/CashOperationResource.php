<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashOperationResource\Pages;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Order;
use App\Models\StockDocument;
use App\Models\Transaction;
use App\Services\Accounting\CashBook;
use App\Traits\RestrictCookAccess;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * «Каса» — ручні операції по рахунках, передусім «Готівка».
 * Ті самі транзакції, що й у «Журналі транзакцій», але з сальдо після кожної
 * операції і з формами під готівку: ЗП, оплата накладної, витрати за статтями,
 * переказ між рахунками, перерахунок.
 */
class CashOperationResource extends Resource
{
    use RestrictCookAccess;

    protected static ?string $model = Transaction::class;
    protected static ?string $slug = 'cash';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Каса';
    protected static ?string $modelLabel = 'Операція каси';
    protected static ?string $pluralModelLabel = 'Каса';
    protected static ?string $navigationGroup = 'Фінанси';
    protected static ?int $navigationSort = 40;

    public static function canViewAny(): bool { return static::canAccess(); }
    public static function canCreate(): bool { return false; }

    /** Менеджер — лише сьогоднішні, як у «Журналі транзакцій». Накладну правлять у накладній, переказ — видалити й внести знову. */
    public static function canEdit(Model $record): bool
    {
        return TransactionResource::canManagerEdit($record)
            && ! $record->stock_document_id
            && $record->category !== CashBook::TRANSFER;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->role === 'admin' && ! $record->stock_document_id;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('account_id')
            ->with(['account', 'employee', 'order.client', 'stockDocument.supplier']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'))
            ->columns([
                TextColumn::make('date')->label('Дата')->date('d.m.Y')->sortable(),
                TextColumn::make('account.name')->label('Рахунок')->badge()->color('gray')
                    ->visible(fn ($livewire) => blank($livewire->tableFilters['account_id']['value'] ?? null)),
                TextColumn::make('category')->label('Стаття')->badge()
                    ->color(fn (Transaction $r) => match (true) {
                        in_array($r->category, CashBook::NON_FLOW, true) => 'info',
                        $r->type === 'income'                           => 'success',
                        default                                         => 'gray',
                    })
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label('Сума')
                    ->money('UAH')
                    ->weight('bold')
                    ->state(fn (Transaction $r) => CashBook::signed($r))
                    ->color(fn ($state) => $state > 0 ? 'success' : 'danger')
                    ->summarize([
                        Sum::make()->label('Надходження')->money('UAH')->query(fn ($query) => $query->where('type', 'income')),
                        Sum::make()->label('Витрати')->money('UAH')->query(fn ($query) => $query->where('type', '!=', 'income')),
                    ]),
                TextColumn::make('details')
                    ->label('Кому / за що')
                    ->state(fn (Transaction $r) => static::details($r))
                    ->description(fn (Transaction $r) => static::details($r) !== $r->comment ? $r->comment : null)
                    ->searchable(['comment'])
                    ->wrap(),
                TextColumn::make('balance_after')
                    ->label('Сальдо')
                    ->state(fn (Transaction $r) => CashBook::balanceAfter($r))
                    ->money('UAH')
                    ->color('gray')
                    ->tooltip('Залишок на рахунку після цієї операції'),
                TextColumn::make('user.name')->label('Вніс')->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('account_id')
                    ->label('Рахунок')
                    ->options(fn () => Account::orderBy('id')->pluck('name', 'id'))
                    ->default(fn () => CashBook::defaultCashAccountId()),
                SelectFilter::make('month')
                    ->label('Місяць')
                    ->options(fn () => static::monthOptions())
                    ->default(now()->format('Y-m'))
                    ->query(function (Builder $query, array $data) {
                        if (! $data['value']) return $query;
                        $start = Carbon::createFromFormat('Y-m-d', $data['value'].'-01')->startOfDay();
                        return $query->whereBetween('date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()]);
                    }),
                SelectFilter::make('category')
                    ->label('Стаття')
                    ->multiple()
                    ->options(fn () => CashBook::allCategories()),
                SelectFilter::make('direction')
                    ->label('Напрям')
                    ->options(['in' => 'Надходження', 'out' => 'Витрати'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'in'    => $query->where('type', 'income'),
                        'out'   => $query->where('type', '!=', 'income'),
                        default => $query,
                    }),
                Filter::make('no_transfers')
                    ->label('Без переказів і перерахунків')
                    ->query(fn (Builder $query) => $query->moneyFlow()),
            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->actions([
                Tables\Actions\Action::make('history')
                    ->label('')
                    ->tooltip('Історія: хто й що міняв')
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->modalHeading(fn (Transaction $r) => 'Історія операції #'.$r->id)
                    ->modalContent(fn (Transaction $r) => static::history($r))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Закрити'),
                Tables\Actions\EditAction::make()
                    ->label('')
                    ->tooltip('Змінити')
                    ->modalHeading('Змінити операцію')
                    ->form([
                        Forms\Components\DatePicker::make('date')->label('Дата')->required()->native(false)->displayFormat('d.m.Y'),
                        Forms\Components\TextInput::make('amount')->label('Сума')->numeric()->minValue(0.01)->prefix('₴')->required(),
                        Forms\Components\Select::make('category')->label('Стаття')
                            ->options(fn (Transaction $record) => $record->type === 'income' ? CashBook::incomeOptions() : CashBook::expenseOptions())
                            ->disabled(fn (Transaction $record) => $record->employee_id || $record->order_id),
                        Forms\Components\Textarea::make('comment')->label('Коментар')->rows(2),
                    ]),
                Tables\Actions\DeleteAction::make()
                    ->label('')
                    ->tooltip('Видалити')
                    ->modalDescription(fn (Transaction $r) => match (true) {
                        $r->category === CashBook::TRANSFER => 'Переказ видалиться з обох рахунків.',
                        (bool) $r->employee_id              => 'Борг співробітнику повернеться.',
                        default                             => 'Сума повернеться в залишок рахунку.',
                    })
                    ->using(fn (Transaction $r) => CashBook::delete($r)),
            ])
            ->paginated([50, 100, 250])
            ->defaultPaginationPageOption(100);
    }

    /** Кому / за що: співробітник, клієнт, накладна або коментар. */
    public static function details(Transaction $r): ?string
    {
        return match (true) {
            (bool) $r->employee_id       => $r->employee?->name ?? 'Видалений співробітник',
            (bool) $r->order_id          => trim(($r->order?->client?->name ?? '').' · замовлення #'.$r->order_id, ' ·'),
            (bool) $r->stock_document_id => 'Накладна #'.$r->stock_document_id.($r->stockDocument?->supplier ? ' · '.$r->stockDocument->supplier->name : ''),
            default                      => $r->comment,
        };
    }

    /** Усі записи журналу по операції: подія, хто, коли, що було → що стало. */
    public static function history(Transaction $r): \Illuminate\Support\HtmlString
    {
        $items = \Spatie\Activitylog\Models\Activity::query()
            ->where('subject_type', Transaction::class)
            ->where('subject_id', $r->id)
            ->with('causer')
            ->orderBy('id')
            ->get();

        if ($items->isEmpty()) {
            return new \Illuminate\Support\HtmlString('<p style="opacity:.7">Історії немає — операцію внесено до того, як її почали записувати'
                .($r->user ? ' (вніс '.e($r->user->name).')' : '').'.</p>');
        }

        return new \Illuminate\Support\HtmlString($items->map(fn ($a) => '<div style="margin-bottom:18px">'
            .'<div style="font-weight:600;margin-bottom:6px">'.e(\App\Support\ActivityLogTranslator::event($a->event))
            .' · '.$a->created_at->format('d.m.Y H:i').' · '.e($a->causer?->name ?? 'Система').'</div>'
            .ActivityResource::renderChanges($a)->toHtml()
            .'</div>')->implode(''));
    }

    // ---- Форми для кнопок у шапці (ListCashOperations) ----

    public static function accountField(?int $default): Forms\Components\Select
    {
        return Forms\Components\Select::make('account_id')
            ->label('Рахунок')
            ->options(fn () => Account::orderBy('id')->pluck('name', 'id'))
            ->default($default)
            ->required();
    }

    public static function dateField(): Forms\Components\DatePicker
    {
        return Forms\Components\DatePicker::make('date')->label('Дата')->default(now())->required()
            ->native(false)->displayFormat('d.m.Y')->maxDate(now());
    }

    public static function incomeForm(?int $account): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                static::accountField($account),
                static::dateField(),
                Forms\Components\Select::make('category')->label('Стаття')
                    ->options(CashBook::incomeOptions())->default(CashBook::CLIENT)->required()->live(),
                Forms\Components\TextInput::make('amount')->label('Сума')->numeric()->minValue(0.01)->prefix('₴')->required(),
            ]),
            Forms\Components\Select::make('order_id')
                ->label('Замовлення клієнта')
                ->helperText('Оплата зарахується на баланс клієнта, як кнопка «Оплата» в замовленні.')
                ->searchable()
                ->getSearchResultsUsing(fn (string $search) => static::searchOrders($search))
                ->getOptionLabelUsing(fn ($value) => static::orderLabel(Order::with('client')->find($value)))
                ->visible(fn (Get $get) => $get('category') === CashBook::CLIENT)
                ->required(fn (Get $get) => $get('category') === CashBook::CLIENT),
            Forms\Components\Textarea::make('comment')->label('Коментар')->rows(2),
        ];
    }

    public static function expenseForm(?int $account): array
    {
        return [
            Forms\Components\Grid::make(2)->schema([
                static::accountField($account),
                static::dateField(),
                Forms\Components\Select::make('category')->label('Стаття')
                    ->options(CashBook::expenseOptions())->required()->live()->searchable()
                    ->columnSpanFull(),
            ]),
            Forms\Components\Select::make('employee_id')
                ->label('Співробітник')
                ->options(fn () => Employee::orderBy('name')->get()->mapWithKeys(fn (Employee $e) => [
                    $e->id => $e->name.((float) $e->balance > 0 ? ' — винні '.number_format((float) $e->balance, 0, '.', ' ').' ₴' : ''),
                ]))
                ->searchable()
                ->visible(fn (Get $get) => $get('category') === CashBook::SALARY)
                ->required(fn (Get $get) => $get('category') === CashBook::SALARY),
            Forms\Components\Select::make('stock_document_id')
                ->label('Неоплачена накладна')
                ->helperText('Накладна стане оплаченою, сума — з накладної.')
                ->options(fn () => StockDocument::posted()->where('type', 'receipt')->where('is_paid', false)
                    ->where('total_sum', '>', 0)->with('supplier')->orderByDesc('operation_date')->limit(200)->get()
                    ->mapWithKeys(fn (StockDocument $d) => [$d->id => '#'.$d->id.' · '.$d->operation_date?->format('d.m')
                        .' · '.($d->supplier?->name ?? 'без постачальника').' · '.number_format((float) $d->total_sum, 2, '.', ' ').' ₴']))
                ->searchable()
                ->visible(fn (Get $get) => $get('category') === CashBook::PURCHASE)
                ->required(fn (Get $get) => $get('category') === CashBook::PURCHASE),
            Forms\Components\TextInput::make('amount')->label('Сума')->numeric()->minValue(0.01)->prefix('₴')
                ->hidden(fn (Get $get) => $get('category') === CashBook::PURCHASE)
                ->required(fn (Get $get) => $get('category') !== CashBook::PURCHASE),
            Forms\Components\Placeholder::make('pnl_hint')->hiddenLabel()
                ->content(fn (Get $get) => match (true) {
                    in_array($get('category'), CashBook::PNL_EXPENSES, true) => 'Піде в P&L рядком «Витрати з каси».',
                    $get('category') === CashBook::SALARY => 'У P&L ЗП вже є з табелю — тут лише гаситься борг.',
                    isset(CashBook::OTHER_EXPENSES[$get('category')]) => 'У P&L не йде: '.CashBook::OTHER_EXPENSES[$get('category')].'.',
                    default => null,
                })
                ->visible(fn (Get $get) => filled($get('category'))),
            Forms\Components\Textarea::make('comment')->label('Коментар')->rows(2)
                ->placeholder('Що купили / кому заплатили')
                ->required(fn (Get $get) => in_array($get('category'), ['Інше', 'Закупівля без накладної'], true)),
        ];
    }

    public static function searchOrders(string $search): array
    {
        return Order::query()
            ->with('client')
            ->where(fn ($q) => $q->where('id', ltrim($search, '#'))
                ->orWhereHas('client', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->limit(30)
            ->get()
            ->mapWithKeys(fn (Order $o) => [$o->id => static::orderLabel($o)])
            ->all();
    }

    public static function orderLabel(?Order $o): ?string
    {
        return $o ? '#'.$o->id.' · '.($o->client?->name ?? '—').' · '.$o->status : null;
    }

    public static function notify(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    /** Місяці від першої операції до поточного — нові першими. */
    public static function monthOptions(): array
    {
        $first = Transaction::whereNotNull('account_id')->min('date');
        $cursor = $first ? Carbon::parse($first)->startOfMonth() : now()->startOfMonth();
        $months = [];
        while ($cursor->lte(now())) {
            $months[$cursor->format('Y-m')] = $cursor->copy()->locale('uk')->translatedFormat('F Y');
            $cursor->addMonth();
        }

        return array_reverse($months, true);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCashOperations::route('/'),
        ];
    }
}
