<?php

namespace App\Http\Controllers;

use App\Filament\Resources\CashOperationResource;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Order;
use App\Models\StockDocument;
use App\Models\Transaction;
use App\Services\Accounting\CashBook;
use App\Support\ActivityLogTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * «Каса» на телефоні: та сама каса, що в адмінці (CashBook), але одна
 * мобільна сторінка, яку можна встановити як застосунок (PWA).
 * Права ті самі: менеджер і адмін; менеджер править лише сьогоднішнє,
 * видаляє лише адмін.
 */
class CashAppController extends Controller
{
    private const PAGE = 40;

    protected function authorizeCash(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['admin', 'manager'], true), 403, 'Каса доступна менеджеру й адміну.');
    }

    public function show()
    {
        $this->authorizeCash();

        return view('cash-app.index', ['user' => auth()->user()]);
    }

    /** Довідники: рахунки, статті, співробітники з боргом, неоплачені накладні. */
    public function refs(): JsonResponse
    {
        $this->authorizeCash();

        return response()->json([
            'user'     => ['name' => auth()->user()->name, 'role' => auth()->user()->role],
            'accounts' => Account::orderBy('id')->get(['id', 'name', 'type'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'cash' => $a->type === 'cash'])->values(),
            'default_account' => CashBook::defaultCashAccountId(),
            'income'   => collect(CashBook::INCOMES)->map(fn ($c) => ['name' => $c, 'hint' => $c === CashBook::CLIENT ? 'на баланс клієнта' : null])->values(),
            'expense'  => collect(CashBook::expenseOptions())->keys()->map(fn ($c) => [
                'name' => $c,
                'hint' => match (true) {
                    $c === CashBook::SALARY                => 'гасить борг співробітнику',
                    $c === CashBook::PURCHASE              => 'оплата накладної',
                    isset(CashBook::OTHER_EXPENSES[$c])    => CashBook::OTHER_EXPENSES[$c],
                    default                                => null,
                },
                'note_required' => in_array($c, ['Інше', 'Закупівля без накладної'], true),
            ])->values(),
            'employees' => Employee::orderBy('name')->get(['id', 'name', 'balance'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'debt' => max(0, round((float) $e->balance, 2))])->values(),
            'invoices'  => StockDocument::posted()->where('type', 'receipt')->where('is_paid', false)
                ->where('total_sum', '>', 0)->with('supplier')->orderByDesc('operation_date')->limit(100)->get()
                ->map(fn (StockDocument $d) => [
                    'id'       => $d->id,
                    'label'    => '#'.$d->id.' · '.$d->operation_date?->format('d.m').' · '.($d->supplier?->name ?? 'без постачальника'),
                    'amount'   => round((float) $d->total_sum, 2),
                ])->values(),
        ]);
    }

    /** Залишок, підсумки дня й місяця і сторінка операцій (нові першими). */
    public function data(Request $request): JsonResponse
    {
        $this->authorizeCash();

        $account = Account::findOrFail($request->integer('account') ?: CashBook::defaultCashAccountId());
        $page = max(0, $request->integer('page'));

        $base = Transaction::where('account_id', $account->id);
        $sum = fn ($from, $type) => round((float) (clone $base)->moneyFlow()->where('date', '>=', $from)
            ->when($type === 'in', fn ($q) => $q->where('type', 'income'), fn ($q) => $q->where('type', '!=', 'income'))
            ->sum('amount'), 2);

        $ops = (clone $base)->with(['employee', 'order.client', 'stockDocument.supplier', 'user'])
            ->orderByDesc('date')->orderByDesc('id')
            ->skip($page * self::PAGE)->take(self::PAGE + 1)->get();

        $today = now()->toDateString();
        $month = now()->startOfMonth()->toDateString();

        return response()->json([
            'account'  => ['id' => $account->id, 'name' => $account->name],
            'balance'  => round((float) $account->balance, 2),
            'today'    => ['in' => $sum($today, 'in'), 'out' => $sum($today, 'out')],
            'month'    => ['in' => $sum($month, 'in'), 'out' => $sum($month, 'out')],
            'ops'      => $ops->take(self::PAGE)->map(fn (Transaction $t) => $this->op($t))->values(),
            'has_more' => $ops->count() > self::PAGE,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeCash();

        return $this->once($request, function () use ($request) {
            $kind = $request->input('kind');
            $data = $this->validateRecord($request, $kind);
            $t = CashBook::record($kind, $data);

            return ['op' => $this->op($t->fresh()), 'message' => $kind === 'income' ? 'Прихід записано' : 'Витрату записано'];
        });
    }

    public function transfer(Request $request): JsonResponse
    {
        $this->authorizeCash();

        return $this->once($request, function () use ($request) {
            $d = $request->validate([
                'account_id'    => ['required', Rule::exists('accounts', 'id')->where('bank_only', 0)],
                'to_account_id' => ['required', 'different:account_id', Rule::exists('accounts', 'id')->where('bank_only', 0)],
                'amount'        => ['required', 'numeric', 'min:0.01'],
                'date'          => ['required', 'date', 'before_or_equal:today'],
                'comment'       => ['nullable', 'string', 'max:500'],
            ], $this->messages(), $this->names());

            CashBook::transfer((int) $d['account_id'], (int) $d['to_account_id'], (float) $d['amount'], $d['date'], $d['comment'] ?? null);

            return ['message' => 'Переказ записано'];
        });
    }

    public function recount(Request $request): JsonResponse
    {
        $this->authorizeCash();

        return $this->once($request, function () use ($request) {
            $d = $request->validate([
                'account_id' => ['required', Rule::exists('accounts', 'id')->where('bank_only', 0)],
                'actual'     => ['required', 'numeric', 'min:0'],
                'date'       => ['required', 'date', 'before_or_equal:today'],
                'comment'    => ['nullable', 'string', 'max:500'],
            ], $this->messages(), $this->names());

            $t = CashBook::recount((int) $d['account_id'], (float) $d['actual'], $d['date'], $d['comment'] ?? null);

            return ['message' => $t
                ? 'Записано різницю '.($t->type === 'income' ? '+' : '−').number_format((float) $t->amount, 2, '.', ' ').' ₴'
                : 'Зійшлось копійка в копійку'];
        });
    }

    public function update(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeCash();
        abort_unless($transaction->account_id && CashOperationResource::canEdit($transaction), 403, 'Цю операцію змінити не можна.');

        $categories = CashBook::editableCategories($transaction);
        $d = $request->validate([
            'date'     => ['required', 'date', 'before_or_equal:today'],
            'amount'   => ['required', 'numeric', 'min:0.01'],
            'category' => [$categories ? 'required' : 'nullable', Rule::in(array_keys($categories) ?: [$transaction->category])],
            'comment'  => ['nullable', 'string', 'max:500'],
        ], $this->messages(), $this->names());

        // Менеджер править лише сьогоднішнє — і не може «перенести» операцію в минуле.
        if (auth()->user()->role !== 'admin' && $d['date'] !== now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Менеджер може ставити лише сьогоднішню дату.']);
        }

        $transaction->update([
            'date'     => $d['date'],
            'amount'   => round((float) $d['amount'], 2),
            'category' => $categories ? $d['category'] : $transaction->category,
            'comment'  => $d['comment'] ?? null,
        ]);

        return response()->json(['op' => $this->op($transaction->fresh()), 'message' => 'Збережено']);
    }

    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->authorizeCash();
        abort_unless($transaction->account_id && CashOperationResource::canDelete($transaction), 403, 'Видаляє лише адмін.');

        CashBook::delete($transaction);

        return response()->json(['message' => 'Видалено']);
    }

    public function history(Transaction $transaction): JsonResponse
    {
        $this->authorizeCash();
        abort_unless((bool) $transaction->account_id, 404);

        $items = Activity::where('subject_type', Transaction::class)->where('subject_id', $transaction->id)
            ->with('causer')->orderBy('id')->get()
            ->map(fn (Activity $a) => [
                'event'   => ActivityLogTranslator::event($a->event),
                'at'      => $a->created_at->format('d.m.Y H:i'),
                'who'     => $a->causer?->name ?? 'Система',
                'changes' => ActivityLogTranslator::changes($a->properties->toArray()),
            ]);

        return response()->json(['items' => $items, 'created_by' => $transaction->user?->name]);
    }

    /** Пошук замовлення для «Оплата клієнта»: номер, імʼя чи телефон. */
    public function orders(Request $request): JsonResponse
    {
        $this->authorizeCash();
        $q = trim((string) $request->input('q'));

        if (mb_strlen($q) < 2) {
            return response()->json(['items' => []]);
        }

        return response()->json(['items' => collect(CashOperationResource::searchOrders($q))
            ->map(fn ($label, $id) => ['id' => $id, 'label' => $label])->values()]);
    }

    // ---- PWA: manifest і service worker без авторизації (у них немає даних) ----

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name'             => 'Каса',
            'short_name'       => 'Каса',
            'start_url'        => '/cash-app',
            'scope'            => '/cash-app',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#0f1412',
            'theme_color'      => '#166534',
            'lang'             => 'uk',
            'icons'            => [
                ['src' => '/cash-icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/cash-icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function serviceWorker()
    {
        return response(view('cash-app.sw')->render(), 200, [
            'Content-Type'           => 'application/javascript',
            'Service-Worker-Allowed' => '/cash-app',
            'Cache-Control'          => 'no-cache',
        ]);
    }

    // ---- допоміжне ----

    protected function validateRecord(Request $request, ?string $kind): array
    {
        if (! in_array($kind, ['income', 'expense'], true)) {
            throw ValidationException::withMessages(['kind' => 'Невідомий тип операції.']);
        }

        $category = $request->input('category');
        $isPurchase = $kind === 'expense' && $category === CashBook::PURCHASE;

        $d = $request->validate([
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('bank_only', 0)],
            'date'       => ['required', 'date', 'before_or_equal:today'],
            'category'   => ['required', Rule::in($kind === 'income' ? CashBook::INCOMES : array_keys(CashBook::expenseOptions()))],
            'amount'     => [$isPurchase ? 'nullable' : 'required', 'numeric', 'min:0.01'],
            'order_id'   => [Rule::requiredIf($kind === 'income' && $category === CashBook::CLIENT), 'nullable', 'exists:orders,id'],
            'employee_id' => [Rule::requiredIf($kind === 'expense' && $category === CashBook::SALARY), 'nullable', 'exists:employees,id'],
            'stock_document_id' => [Rule::requiredIf($isPurchase), 'nullable',
                Rule::exists('stock_documents', 'id')->where('type', 'receipt')->where('is_paid', false)->where('status', StockDocument::STATUS_POSTED)],
            'comment'    => [Rule::requiredIf(in_array($category, ['Інше', 'Закупівля без накладної'], true)), 'nullable', 'string', 'max:500'],
        ], [
            'stock_document_id.exists' => 'Накладна вже оплачена або ще не проведена.',
        ] + $this->messages(), $this->names());

        return $d;
    }

    /**
     * Захист від подвійного запису: телефон на поганому звʼязку повторює запит.
     * Кожна відкрита форма має свій ключ; той самий ключ удруге не проходить.
     */
    protected function once(Request $request, \Closure $fn): JsonResponse
    {
        $key = (string) $request->header('X-Idempotency-Key');
        $cacheKey = 'cash-app:'.auth()->id().':'.$key;

        if ($key === '') {
            return response()->json($fn());
        }

        if (Cache::has($cacheKey)) {
            return response()->json(Cache::get($cacheKey) + ['duplicate' => true]);
        }

        // Два однакові запити одночасно: другий чекає, поки перший запише.
        return Cache::lock($cacheKey.':lock', 15)->block(10, function () use ($cacheKey, $fn) {
            if (Cache::has($cacheKey)) {
                return response()->json(Cache::get($cacheKey) + ['duplicate' => true]);
            }

            $result = $fn();
            Cache::put($cacheKey, $result, now()->addHour());

            return response()->json($result);
        });
    }

    protected function op(Transaction $t): array
    {
        return [
            'id'          => $t->id,
            'date'        => $t->date->toDateString(),
            'type'        => $t->type,
            'category'    => $t->category,
            'amount'      => CashBook::signed($t),
            'details'     => CashOperationResource::details($t),
            'comment'     => $t->comment,
            'user'        => $t->user?->name,
            'balance'     => CashBook::balanceAfter($t),
            'non_flow'    => in_array($t->category, CashBook::NON_FLOW, true),
            'can_edit'    => CashOperationResource::canEdit($t),
            'can_delete'  => CashOperationResource::canDelete($t),
            'categories'  => array_keys(CashBook::editableCategories($t)),
        ];
    }

    /** Тексти помилок українською — локаль застосунку англійська. */
    protected function messages(): array
    {
        return [
            'required'        => 'Вкажіть :attribute.',
            'numeric'         => ':attribute — має бути число.',
            'min'             => ':attribute — щонайменше :min.',
            'date'            => 'Невірна :attribute.',
            'before_or_equal' => 'Дата не може бути в майбутньому.',
            'in'              => 'Оберіть :attribute зі списку.',
            'exists'          => 'Такого значення (:attribute) немає.',
            'different'       => 'Рахунки «звідки» і «куди» мають відрізнятись.',
            'max'             => ':attribute — задовгий.',
        ];
    }

    protected function names(): array
    {
        return [
            'account_id' => 'рахунок', 'to_account_id' => 'куди', 'amount' => 'сума', 'actual' => 'фактична сума',
            'date' => 'дата', 'category' => 'стаття', 'order_id' => 'замовлення', 'employee_id' => 'співробітник',
            'stock_document_id' => 'накладна', 'comment' => 'коментар',
        ];
    }
}
