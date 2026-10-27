<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\BankOperation;
use App\Models\CounterpartyRule;
use App\Models\Order;
use App\Models\StockDocument;
use App\Models\Supplier;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * «Звірка»: кожна операція з банку стає записом CRM або позначкою «не стосується».
 *
 * Що CRM підбирає сама (пропозиція):
 *  - надходження → оплати клієнтів, уже внесені менеджерами (номери замовлень
 *    у призначенні: «рахунок 2207/2208»), або одна оплата на ту саму суму;
 *  - списання → уже внесена витрата на ту саму суму;
 *  - платіж постачальнику (ЄДРПОУ = ІПН постачальника) → неоплачені накладні;
 *  - переказ між своїми рахунками → «не стосується»;
 *  - контрагент із правилом (як у Saldo) → стаття / ЗП / «не стосується».
 *
 * Автоматично застосовуємо лише те, що не рухає нових грошей або що людина
 * сама задала правилом. Закриття накладних — після кліку «Прийняти».
 */
class BankReconciler
{
    public const LINK     = 'link';
    public const INVOICES = 'invoices';
    public const INTERNAL = 'internal';
    public const RULE     = 'rule';

    /** Вікно пошуку пари в CRM навколо дати банку. */
    private const DAYS_BEFORE = 4;
    private const DAYS_AFTER  = 3;

    /** @var array<int, ?array> */
    private array $cache = [];

    public static function from(): string
    {
        return (string) config('services.finance.reconcile_from', '2026-10-01');
    }

    /** Рахунки, які звіряємо: підключені до банку й доступні для оплат у CRM. */
    public static function accountIds(): array
    {
        return Account::query()->whereNotNull('mono_token')->pluck('id')->all();
    }

    public static function queue(): Builder
    {
        return BankOperation::query()
            ->whereIn('account_id', static::accountIds())
            ->where('operated_at', '>=', static::from());
    }

    // ------------------------------------------------------------------
    // Пропозиції
    // ------------------------------------------------------------------

    /**
     * Що це за операція, на думку CRM.
     *
     * @return array{kind: string, label: string, auto: bool, ids?: array<int>, rule?: CounterpartyRule}|null
     */
    public function suggest(BankOperation $op): ?array
    {
        if ($op->reconcile_status) {
            return null;
        }

        return $this->cache[$op->id] ??= $this->guess($op);
    }

    private function guess(BankOperation $op): ?array
    {
        $amount = abs((float) $op->amount);
        $text = trim($op->description.' '.$op->comment);

        if (BankOperation::query()->whereKey($op->id)->internal()->exists()) {
            return ['kind' => self::INTERNAL, 'label' => 'Переказ між своїми рахунками', 'auto' => true];
        }

        // Уже внесене в CRM: оплати клієнтів по замовленнях з призначення або одна сума.
        $free = $this->unlinked($op);
        if ($op->isIncoming()) {
            $orders = $this->orderIds($text);
            if ($orders) {
                // Ті оплати цих замовлень, що разом дають суму платежу (клієнт міг доплатити окремо).
                $byOrder = $free->whereIn('order_id', $orders)->values()->all();
                foreach (range(1, min(4, count($byOrder))) as $n) {
                    foreach ($this->combinations($byOrder, $n) as $combo) {
                        if (abs(array_sum(array_map(fn ($t) => (float) $t->amount, $combo)) - $amount) < 0.01) {
                            return $this->linkHint(collect($combo));
                        }
                    }
                }
            }
        }
        $same = $free->filter(fn (Transaction $t) => abs((float) $t->amount - $amount) < 0.01)
            ->sortBy(fn (Transaction $t) => abs($t->date->diffInDays($op->operated_at->copy()->startOfDay())));
        if ($same->count() > 1) {
            // Кілька записів на ту саму суму — беремо того, чиє імʼя збігається з платником.
            $named = $same->filter(fn (Transaction $t) => $this->sameName($t, $op));
            if ($named->count() === 1) {
                return $this->linkHint($named);
            }

            return ['auto' => false] + $this->linkHint($same->take(1));
        }
        if ($same->isNotEmpty()) {
            return $this->linkHint($same);
        }

        if (! $op->isIncoming() && ($invoices = $this->invoicesFor($op, $amount))) {
            $docs = StockDocument::with('supplier')->findMany($invoices);

            return [
                'kind'  => self::INVOICES,
                'label' => 'Оплата накладн'.(count($invoices) > 1 ? 'их' : 'ої').' '.$docs->map(fn ($d) => '#'.$d->id)->implode(', ')
                    .' · '.($docs->first()?->supplier?->name ?? ''),
                'auto'  => false,
                'ids'   => $invoices,
            ];
        }

        if ($rule = CounterpartyRule::for($op)) {
            return ['kind' => self::RULE, 'label' => 'Правило: '.$this->ruleLabel($rule), 'auto' => true, 'rule' => $rule];
        }

        return null;
    }

    private function linkHint(Collection $txs): array
    {
        $label = $txs->map(fn (Transaction $t) => match (true) {
            (bool) $t->order_id          => 'оплата замовлення #'.$t->order_id,
            (bool) $t->stock_document_id => 'накладна #'.$t->stock_document_id,
            (bool) $t->employee_id       => 'ЗП · '.($t->employee?->name ?? '#'.$t->employee_id),
            default                      => ($t->category ?: 'запис').' #'.$t->id,
        })->implode(', ');

        return ['kind' => self::LINK, 'label' => 'Уже в CRM: '.$label, 'auto' => true, 'ids' => $txs->pluck('id')->values()->all()];
    }

    /** Хоч одне слово імені клієнта (від 4 літер) є серед слів платника з банку. */
    private function sameName(Transaction $t, BankOperation $op): bool
    {
        $words = fn (string $s) => array_filter(preg_split('/[^\p{L}]+/u', mb_strtolower($s)), fn ($w) => mb_strlen($w) >= 4);
        // Цілими словами: «Роман» не має збігатися з «Романівна».
        return (bool) array_intersect(
            $words((string) ($t->order?->client?->name ?? $t->employee?->name ?? '')),
            $words($op->counter_name.' '.$op->description),
        );
    }

    public function ruleLabel(CounterpartyRule $rule): string
    {
        return match ($rule->action) {
            CounterpartyRule::SALARY => 'ЗП · '.($rule->employee?->name ?? '—'),
            CounterpartyRule::IGNORE => 'не стосується',
            default                  => (string) $rule->category,
        };
    }

    /** Записи CRM на цьому рахунку, ще не привʼязані до банку, біля дати операції. */
    private function unlinked(BankOperation $op): Collection
    {
        $day = $op->operated_at->copy()->startOfDay();

        return Transaction::query()
            ->with(['employee', 'order.client'])
            ->where('account_id', $op->account_id)
            ->when($op->isIncoming(), fn ($q) => $q->where('type', 'income'), fn ($q) => $q->whereIn('type', ['expense', 'refund']))
            ->whereBetween('date', [$day->copy()->subDays(self::DAYS_BEFORE)->toDateString(), $day->copy()->addDays(self::DAYS_AFTER)->toDateString()])
            ->whereNotIn('id', DB::table('bank_operation_transaction')->select('transaction_id'))
            ->get();
    }

    /** Номери замовлень у призначенні («рахунок 2207/2208», «№2185»). Дати й суми не плутаємо. */
    public function orderIds(string $text): array
    {
        $clean = preg_replace('/\d{1,2}[.\/]\d{1,2}[.\/]\d{2,4}|\d+[.,]\d{2}\b/u', ' ', $text);
        preg_match_all('/(?<!\d)(\d{3,5})(?!\d)/u', (string) $clean, $m);
        $ids = array_values(array_unique(array_map('intval', $m[1])));

        return $ids ? Order::whereIn('id', $ids)->pluck('id')->all() : [];
    }

    /**
     * Неоплачені накладні постачальника на суму платежу: одна або 2–3 за останні 3 тижні.
     *
     * @return array<int>|null
     */
    private function invoicesFor(BankOperation $op, float $amount): ?array
    {
        if (! $op->counter_edrpou) {
            return null;
        }
        $suppliers = Supplier::where('inn', $op->counter_edrpou)->pluck('id');
        if ($suppliers->isEmpty()) {
            return null;
        }

        $docs = StockDocument::query()->posted()
            ->whereIn('supplier_id', $suppliers)->where('type', 'receipt')->where('is_paid', false)
            ->where('operation_date', '>=', $op->operated_at->copy()->subDays(21)->startOfDay())
            ->where('operation_date', '<=', $op->operated_at->copy()->addDay()->endOfDay())
            ->orderBy('operation_date')->get(['id', 'total_sum', 'operation_date']);

        $close = fn ($sum) => abs($sum - $amount) <= max(2.0, 0.005 * $amount);
        foreach ([1, 2, 3] as $n) {
            foreach ($this->combinations($docs->all(), $n) as $combo) {
                if ($close(array_sum(array_map(fn ($d) => (float) $d->total_sum, $combo)))) {
                    return array_map(fn ($d) => $d->id, $combo);
                }
            }
        }

        return null;
    }

    private function combinations(array $items, int $n, int $start = 0): \Generator
    {
        if ($n === 0) {
            yield [];

            return;
        }
        for ($i = $start; $i <= count($items) - $n; $i++) {
            foreach ($this->combinations($items, $n - 1, $i + 1) as $rest) {
                yield [$items[$i], ...$rest];
            }
        }
    }

    // ------------------------------------------------------------------
    // Дії
    // ------------------------------------------------------------------

    /** Застосувати пропозицію CRM. */
    public function accept(BankOperation $op, ?int $userId = null): bool
    {
        $s = $this->suggest($op);
        if (! $s) {
            return false;
        }

        match ($s['kind']) {
            self::LINK     => $this->link($op, $s['ids'], $userId),
            self::INVOICES => $this->payInvoices($op, $s['ids'], $userId),
            self::INTERNAL => $this->ignore($op, 'Переказ між своїми рахунками', false, $userId),
            self::RULE     => $this->applyRule($op, $s['rule'], $userId),
        };

        return true;
    }

    /** Автопідбір: застосовує лише «безпечні» пропозиції (див. опис класу). */
    public function autoRun(?int $userId = null): array
    {
        $done = ['link' => 0, 'internal' => 0, 'rule' => 0, 'left' => 0];
        // Спершу список: якщо гортати порціями, розібрані випадають із вибірки і частину рядків пропускаємо.
        $ids = static::queue()->whereNull('reconcile_status')->orderBy('operated_at')->pluck('id');
        foreach ($ids as $id) {
            $op = BankOperation::find($id);
            $s = $this->suggest($op);
            if ($s && $s['auto']) {
                $this->accept($op, $userId);
                $done[$s['kind']]++;
            } else {
                $done['left']++;
            }
        }

        return $done;
    }

    /** Привʼязати вже внесені записи CRM. Гроші не рухаються. */
    public function link(BankOperation $op, array $transactionIds, ?int $userId = null): void
    {
        DB::transaction(function () use ($op, $transactionIds, $userId) {
            $op->transactions()->syncWithoutDetaching($transactionIds);
            $this->mark($op, BankOperation::MATCHED, null, $userId);
        });
    }

    /** Оплата накладних цим платежем: накладна сама створює запис оплати, ставимо дату банку. */
    public function payInvoices(BankOperation $op, array $docIds, ?int $userId = null): void
    {
        DB::transaction(function () use ($op, $docIds, $userId) {
            foreach (StockDocument::findMany($docIds) as $doc) {
                if (! $doc->is_paid) {
                    $doc->update(['account_id' => $op->account_id, 'is_paid' => true]);
                }
                $t = Transaction::where('stock_document_id', $doc->id)->first();
                if ($t) {
                    $t->update(['date' => $op->operated_at->toDateString()]);
                    $op->transactions()->syncWithoutDetaching([$t->id => ['created_by_reconcile' => true]]);
                }
            }
            $this->mark($op, BankOperation::MATCHED, null, $userId);
        });
    }

    /** Оплата клієнта за замовленням — як кнопка «Оплата» в замовленні. */
    public function clientPayment(BankOperation $op, int $orderId, ?int $userId = null): void
    {
        $this->create($op, [
            'type' => 'income', 'category' => CashBook::CLIENT, 'order_id' => $orderId, 'method' => 'transfer',
        ], $userId);
    }

    /** Витрата або надходження за статтею; ЗП — на співробітника. За бажанням — запамʼятати контрагента. */
    public function record(BankOperation $op, string $category, ?int $employeeId, bool $remember, ?int $userId = null): void
    {
        DB::transaction(function () use ($op, $category, $employeeId, $remember, $userId) {
            $income = $op->isIncoming();
            $this->create($op, array_filter([
                'type'        => $income ? 'income' : 'expense',
                'category'    => $employeeId ? CashBook::SALARY : $category,
                'employee_id' => $employeeId,
            ]), $userId);

            if ($remember) {
                CounterpartyRule::remember($op, $employeeId
                    ? ['action' => CounterpartyRule::SALARY, 'employee_id' => $employeeId, 'category' => null]
                    : ['action' => $income ? CounterpartyRule::INCOME : CounterpartyRule::EXPENSE, 'category' => $category, 'employee_id' => null], $userId);
            }
        });
    }

    public function ignore(BankOperation $op, ?string $note, bool $remember, ?int $userId = null): void
    {
        DB::transaction(function () use ($op, $note, $remember, $userId) {
            $this->mark($op, BankOperation::IGNORED, $note ?: 'Не стосується', $userId);
            if ($remember) {
                CounterpartyRule::remember($op, ['action' => CounterpartyRule::IGNORE, 'category' => null, 'employee_id' => null], $userId);
            }
        });
    }

    private function applyRule(BankOperation $op, CounterpartyRule $rule, ?int $userId): void
    {
        match ($rule->action) {
            CounterpartyRule::IGNORE => $this->ignore($op, 'Правило: не стосується', false, $userId),
            CounterpartyRule::SALARY => $this->record($op, CashBook::SALARY, $rule->employee_id, false, $userId),
            default                  => $this->record($op, (string) $rule->category, null, false, $userId),
        };
    }

    /**
     * Скасувати розбір: записи, створені «Звіркою», прибираємо (накладна знову
     * стає неоплаченою), привʼязані — лише відвʼязуємо.
     */
    public function reset(BankOperation $op): void
    {
        DB::transaction(function () use ($op) {
            foreach ($op->transactions()->get() as $t) {
                if ($t->pivot->created_by_reconcile) {
                    $t->stock_document_id
                        ? StockDocument::find($t->stock_document_id)?->update(['is_paid' => false])
                        : $t->delete();
                }
            }
            $op->transactions()->detach();
            $op->update(['reconcile_status' => null, 'reconcile_note' => null, 'reconciled_by' => null, 'reconciled_at' => null]);
            unset($this->cache[$op->id]);
        });
    }

    private function create(BankOperation $op, array $data, ?int $userId): Transaction
    {
        return DB::transaction(function () use ($op, $data, $userId) {
            $t = Transaction::create($data + [
                'account_id' => $op->account_id,
                'amount'     => abs((float) $op->amount),
                'date'       => $op->operated_at->toDateString(),
                'comment'    => trim('Банк: '.($op->counter_name ?? '').' · '.trim($op->description.' '.$op->comment), ' ·'),
                'user_id'    => $userId,
            ]);
            $op->transactions()->attach($t->id, ['created_by_reconcile' => true]);
            $this->mark($op, BankOperation::MATCHED, null, $userId);

            return $t;
        });
    }

    private function mark(BankOperation $op, string $status, ?string $note, ?int $userId): void
    {
        $op->update(['reconcile_status' => $status, 'reconcile_note' => $note, 'reconciled_by' => $userId, 'reconciled_at' => Carbon::now()]);
        unset($this->cache[$op->id]);
    }
}
