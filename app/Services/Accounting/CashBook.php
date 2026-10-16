<?php

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\StockDocument;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Ручні операції по рахунках (передусім «Готівка»): статті, сальдо після
 * кожної операції, переказ між рахунками, перерахунок каси.
 *
 * Що з цього йде в P&L. ЗП (employee_id) там уже є з табелю, накладні
 * (stock_document_id) — через склад, оренда й комунальні — з налаштувань.
 * Тож у P&L потрапляють лише статті з PNL_EXPENSES, інакше рахували б двічі.
 */
class CashBook
{
    public const SALARY    = 'Виплата ЗП';
    public const PURCHASE  = 'Закупівля';
    public const CLIENT    = 'Оплата клієнта';
    public const TRANSFER  = 'Переказ між рахунками';
    public const RECOUNT   = 'Перерахунок каси';

    /** Витрати, які йдуть у P&L рядком «Витрати з каси». */
    public const PNL_EXPENSES = [
        'Господарські',
        'Закупівля без накладної',
        'Транспорт і пальне',
        'Обладнання та ремонт',
        'Реклама',
        'Податки та банк',
        'Інше',
    ];

    /** Витрати, що рухають гроші, але в P&L уже враховані деінде або це не витрата бізнесу. */
    public const OTHER_EXPENSES = [
        'Оренда'               => 'в P&L — з налаштувань',
        'Комунальні'           => 'в P&L — з налаштувань',
        'Вилучення власником'  => 'не витрата бізнесу',
    ];

    public const INCOMES = [
        self::CLIENT,
        'Внесення власником',
        'Інше надходження',
    ];

    /** Не гроші бізнесу, а рух між своїми рахунками чи виправлення залишку. */
    public const NON_FLOW = [self::TRANSFER, self::RECOUNT];

    /** Статті витрат для форми: назва => підказка. */
    public static function expenseOptions(): array
    {
        $opts = [
            self::SALARY   => self::SALARY.' (гасить борг співробітнику)',
            self::PURCHASE => 'Оплата накладної',
        ];
        foreach (self::PNL_EXPENSES as $c) {
            $opts[$c] = $c;
        }
        foreach (self::OTHER_EXPENSES as $c => $hint) {
            $opts[$c] = "{$c} ({$hint})";
        }

        return $opts;
    }

    public static function incomeOptions(): array
    {
        return array_combine(self::INCOMES, self::INCOMES);
    }

    /** Усі статті — для фільтра. */
    public static function allCategories(): array
    {
        $all = array_merge(
            self::INCOMES,
            [self::SALARY, self::PURCHASE],
            self::PNL_EXPENSES,
            array_keys(self::OTHER_EXPENSES),
            self::NON_FLOW,
            ['Повернення коштів', 'Скасування оплати'],
        );

        return array_combine($all, $all);
    }

    public static function defaultCashAccountId(): ?int
    {
        return Account::where('type', 'cash')->orderBy('id')->value('id')
            ?? Account::where('is_default', true)->value('id');
    }

    /** Надходження з плюсом, усе інше (витрата, повернення) — з мінусом. Так само рахує PaymentObserver. */
    public static function signed(Transaction $t): float
    {
        return $t->type === 'income' ? (float) $t->amount : -(float) $t->amount;
    }

    /**
     * Сальдо рахунку після кожної операції: від поточного балансу назад.
     * Порядок той самий, що в таблиці: дата, потім id.
     *
     * @return array<int, float> transaction id => сальдо після
     */
    public static function balancesAfter(int $accountId): array
    {
        return self::$balances[$accountId] ??= (function () use ($accountId) {
            $balance = (float) Account::withBankOnly()->whereKey($accountId)->value('balance');
            $map = [];

            Transaction::where('account_id', $accountId)
                ->orderByDesc('date')->orderByDesc('id')
                ->get(['id', 'type', 'amount'])
                ->each(function (Transaction $t) use (&$balance, &$map) {
                    $map[$t->id] = round($balance, 2);
                    $balance -= self::signed($t);
                });

            return $map;
        })();
    }

    /** @var array<int, array<int, float>> */
    private static array $balances = [];

    /** Скинути кеш сальдо — після будь-якої зміни транзакцій (Transaction::booted). */
    public static function flush(): void
    {
        self::$balances = [];
    }

    public static function balanceAfter(Transaction $t): ?float
    {
        return $t->account_id ? (self::balancesAfter($t->account_id)[$t->id] ?? null) : null;
    }

    /** Звичайна операція з форми «+ Надходження» / «− Витрата». */
    public static function record(string $type, array $data): Transaction
    {
        return DB::transaction(function () use ($type, $data) {
            // Оплата накладної: накладна сама створює свою транзакцію (StockDocument::syncTransaction),
            // тож ставимо їй рахунок і «оплачено», а не пишемо другу витрату.
            if ($type === 'expense' && ($data['category'] ?? null) === self::PURCHASE) {
                $doc = StockDocument::findOrFail($data['stock_document_id']);
                $doc->update(['account_id' => $data['account_id'], 'is_paid' => true]);

                // Дата — коли фактично заплатили, а не дата накладної.
                $t = Transaction::where('stock_document_id', $doc->id)->firstOrFail();
                $t->update(['date' => $data['date'], 'comment' => trim($t->comment.' '.($data['comment'] ?? ''))]);

                return $t;
            }

            return Transaction::create([
                'type'        => $type,
                'category'    => $data['category'],
                'amount'      => round(abs((float) $data['amount']), 2),
                'account_id'  => $data['account_id'],
                'date'        => $data['date'],
                'comment'     => $data['comment'] ?? null,
                'order_id'    => $type === 'income' && $data['category'] === self::CLIENT ? ($data['order_id'] ?? null) : null,
                'employee_id' => $type === 'expense' && $data['category'] === self::SALARY ? ($data['employee_id'] ?? null) : null,
                'method'      => self::isCash((int) $data['account_id']) ? 'cash' : null,
                'user_id'     => auth()->id(),
            ]);
        });
    }

    /** Переказ: дві операції з посиланням одна на одну в коментарі. */
    public static function transfer(int $fromId, int $toId, float $amount, string $date, ?string $comment = null): array
    {
        return DB::transaction(function () use ($fromId, $toId, $amount, $date, $comment) {
            $from = Account::findOrFail($fromId);
            $to   = Account::findOrFail($toId);
            $base = ['category' => self::TRANSFER, 'amount' => round(abs($amount), 2), 'date' => $date, 'user_id' => auth()->id()];
            $note = $comment ? " · {$comment}" : '';

            $out = Transaction::create($base + ['type' => 'expense', 'account_id' => $from->id, 'comment' => "→ «{$to->name}»{$note}"]);
            $in  = Transaction::create($base + ['type' => 'income', 'account_id' => $to->id, 'comment' => "← «{$from->name}»{$note} [пара #{$out->id}]"]);
            $out->updateQuietly(['comment' => $out->comment." [пара #{$in->id}]"]);

            return [$out, $in];
        });
    }

    /** Друга половина переказу. */
    public static function transferPair(Transaction $t): ?Transaction
    {
        if ($t->category !== self::TRANSFER || ! preg_match('/\[пара #(\d+)\]/u', (string) $t->comment, $m)) {
            return null;
        }

        return Transaction::where('category', self::TRANSFER)->find((int) $m[1]);
    }

    /** Видалити операцію; переказ — обидві половини. */
    public static function delete(Transaction $t): void
    {
        DB::transaction(function () use ($t) {
            self::transferPair($t)?->delete();
            $t->delete();
        });
    }

    /**
     * Перерахунок: людина каже, скільки грошей фактично, різницю пишемо
     * корекцією. Повертає корекцію або null, якщо все зійшлось.
     */
    public static function recount(int $accountId, float $actual, string $date, ?string $comment = null): ?Transaction
    {
        $account = Account::findOrFail($accountId);
        $diff = round($actual - (float) $account->balance, 2);

        if (abs($diff) < 0.01) {
            return null;
        }

        return Transaction::create([
            'type'       => $diff > 0 ? 'income' : 'expense',
            'category'   => self::RECOUNT,
            'amount'     => abs($diff),
            'account_id' => $account->id,
            'date'       => $date,
            'comment'    => trim('Фактично '.number_format($actual, 2, '.', ' ').' ₴, у CRM було '
                .number_format((float) $account->balance, 2, '.', ' ').' ₴. '.($comment ?? '')),
            'user_id'    => auth()->id(),
        ]);
    }

    public static function isCash(int $accountId): bool
    {
        return Account::whereKey($accountId)->value('type') === 'cash';
    }

    /**
     * Витрати з каси для P&L: день => сума, і стаття => сума за період.
     *
     * @return array{byDay: array<string, float>, byCategory: array<string, float>}
     */
    public static function pnlExpenses(string $from, string $to): array
    {
        $rows = Transaction::where('type', 'expense')
            ->whereNotNull('account_id')
            ->whereNull('employee_id')
            ->whereNull('stock_document_id')
            ->whereNull('order_id')
            ->whereIn('category', self::PNL_EXPENSES)
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'category', 'amount']);

        $byCategory = $rows->groupBy('category')->map(fn ($g) => round((float) $g->sum('amount'), 2))->sortDesc()->all();

        return [
            'byDay'      => $rows->groupBy(fn ($t) => $t->date->format('Y-m-d'))->map(fn ($g) => round((float) $g->sum('amount'), 2))->all(),
            'byCategory' => $byCategory,
        ];
    }
}
