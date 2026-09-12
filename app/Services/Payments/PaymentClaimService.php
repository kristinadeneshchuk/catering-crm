<?php

namespace App\Services\Payments;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentClaim;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Inbox\WebhookNotifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Заяви про оплату: хтось каже «гроші є» — менеджер вирішує, чи це правда.
 *
 * Заява ніколи не чіпає is_paid. Гроші в системі зʼявляються лише в confirm():
 * там створюється звичайна транзакція, і далі працює чинний ланцюжок —
 * Client::recalculateOrderPaymentStatus() ставить прапорець і шле вебхук.
 */
class PaymentClaimService
{
    public function __construct(private WebhookNotifier $webhooks)
    {
    }

    // -------------------------------------------------------------------------
    // Створення
    // -------------------------------------------------------------------------

    /**
     * @param  array{
     *     source: string, amount: float|int|string, method?: string,
     *     reported_by_type: string, reported_by_id?: int|null,
     *     employee_id?: int|null, order_day_id?: int|null, route_stop_id?: int|null,
     *     invoice_id?: int|null, attachment_path?: string|null, comment?: string|null,
     * }  $data
     */
    public function create(Order $order, array $data): PaymentClaim
    {
        $source = $data['source'];

        if (! array_key_exists($source, PaymentClaim::sourceLabels())) {
            throw ValidationException::withMessages(['source' => 'Невідоме джерело заяви.']);
        }

        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сума має бути більшою за нуль.']);
        }

        return DB::transaction(function () use ($order, $data, $source, $amount) {
            $claim = PaymentClaim::create([
                'order_id'         => $order->id,
                'client_id'        => $order->client_id,
                'source'           => $source,
                'amount'           => $amount,
                'method'           => $data['method'] ?? $this->defaultMethod($source),
                'reported_by_type' => $data['reported_by_type'],
                'reported_by_id'   => $data['reported_by_id'] ?? null,
                'employee_id'      => $data['employee_id'] ?? null,
                'order_day_id'     => $data['order_day_id'] ?? null,
                'route_stop_id'    => $data['route_stop_id'] ?? null,
                'invoice_id'       => $data['invoice_id'] ?? null,
                'attachment_path'  => $data['attachment_path'] ?? null,
                'comment'          => $data['comment'] ?? null,
                'status'           => PaymentClaim::STATUS_PENDING,
            ]);

            if ($claim->isCash()) {
                $this->pairCash($claim);
            }

            return $claim->fresh(['pairedClaim']);
        });
    }

    /**
     * Виставили рахунок — фіксуємо очікування. Грошей за цим ще немає, але
     * менеджер бачить у списку, на що чекати, і підтверджує, коли переказ прийшов.
     *
     * Повторний рахунок на те саме замовлення нову заяву не плодить.
     */
    public function recordInvoice(Invoice $invoice, string $reportedByType, ?int $reportedById = null): PaymentClaim
    {
        $existing = PaymentClaim::where('invoice_id', $invoice->id)->first();

        if ($existing) {
            return $existing;
        }

        return $this->create($invoice->order, [
            'source'           => PaymentClaim::SOURCE_INVOICE_SENT,
            'amount'           => $invoice->amount,
            'method'           => PaymentClaim::METHOD_TRANSFER,
            'reported_by_type' => $reportedByType,
            'reported_by_id'   => $reportedById,
            'invoice_id'       => $invoice->id,
        ]);
    }

    /**
     * Зводимо в пару «клієнт сказав» і «курʼєр підтвердив» про ту саму готівку.
     *
     * Це одні й ті самі гроші, описані двічі. Без пари менеджер підтвердив би
     * обидві заяви і записав би оплату вдвічі.
     */
    private function pairCash(PaymentClaim $claim): void
    {
        $partnerSource = $claim->source === PaymentClaim::SOURCE_CLIENT_CASH
            ? PaymentClaim::SOURCE_COURIER_CASH
            : PaymentClaim::SOURCE_CLIENT_CASH;

        $query = PaymentClaim::pending()
            ->where('order_id', $claim->order_id)
            ->where('source', $partnerSource)
            ->whereNull('paired_claim_id')
            ->where('id', '!=', $claim->id);

        // Та сама доставка, якщо обидві сторони її знають. Інакше — будь-яка
        // вільна пара по замовленню: клієнт зазвичай не знає номера доставки.
        if ($claim->order_day_id) {
            $query->where(fn ($q) => $q->where('order_day_id', $claim->order_day_id)->orWhereNull('order_day_id'));
        }

        $partner = $query->orderBy('id')->first();

        if (! $partner) {
            return;
        }

        $claim->update(['paired_claim_id' => $partner->id]);
        $partner->update(['paired_claim_id' => $claim->id]);

        // Клієнт не знає ні курʼєра, ні доставки — беремо їх у напарника.
        foreach (['employee_id', 'order_day_id', 'route_stop_id'] as $field) {
            if (! $claim->{$field} && $partner->{$field}) {
                $claim->update([$field => $partner->{$field}]);
            }
            if (! $partner->{$field} && $claim->{$field}) {
                $partner->update([$field => $claim->{$field}]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Підтвердження і відхилення
    // -------------------------------------------------------------------------

    /**
     * Менеджер підтверджує: гроші справді прийшли. Лише тут вони зʼявляються
     * в системі — як звичайна транзакція, з касою і способом оплати.
     *
     * @param  float|null  $amount  виправлена сума; null — як у заяві
     */
    public function confirm(PaymentClaim $claim, Account $account, User $user, ?float $amount = null): Transaction
    {
        $this->authorize($user);

        if (! $claim->isPending()) {
            throw ValidationException::withMessages(['claim' => 'Заяву вже опрацьовано.']);
        }

        $amount = round($amount ?? (float) $claim->amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Сума має бути більшою за нуль.']);
        }

        $transaction = DB::transaction(function () use ($claim, $account, $user, $amount) {
            $order = $claim->order;

            // Транзакція створюється від імені менеджера: запобіжник у
            // Transaction::creating перевіряє саме його роль.
            $transaction = Transaction::create([
                'type'       => 'income',
                'category'   => 'Оплата клієнта',
                'amount'     => $amount,
                'date'       => now(),
                'order_id'   => $order->id,
                'client_id'  => $order->client_id,
                'account_id' => $account->id,
                'method'     => $claim->method,
                'user_id'    => $user->id,
                'comment'    => $this->comment($claim, $amount),
            ]);

            // Суму заяви не перезаписуємо: вона — те, що людина ЗАЯВИЛА. Фактичні
            // гроші лежать у транзакції. Інакше розбіжність «9400 проти 9300»
            // зникла б з історії в момент підтвердження.
            $claim->update([
                'status'         => PaymentClaim::STATUS_CONFIRMED,
                'confirmed_by'   => $user->id,
                'confirmed_at'   => now(),
                'transaction_id' => $transaction->id,
            ]);

            // Напарник описував ті самі гроші — друга транзакція подвоїла б оплату.
            if ($claim->paired_claim_id) {
                PaymentClaim::whereKey($claim->paired_claim_id)
                    ->where('status', PaymentClaim::STATUS_PENDING)
                    ->update([
                        'status'         => PaymentClaim::STATUS_SUPERSEDED,
                        'confirmed_by'   => $user->id,
                        'confirmed_at'   => now(),
                        'transaction_id' => $transaction->id,
                    ]);
            }

            // Рахунок чекав саме цих грошей. Якщо замовлення тепер оплачене,
            // очікування «виставлено рахунок» по ньому більше не актуальні.
            if ($order->fresh()->is_paid) {
                PaymentClaim::pending()
                    ->where('order_id', $order->id)
                    ->where('source', PaymentClaim::SOURCE_INVOICE_SENT)
                    ->update(['status' => PaymentClaim::STATUS_SUPERSEDED]);
            }

            return $transaction;
        });

        $this->webhooks->claimResolved($claim->fresh());

        return $transaction;
    }

    public function reject(PaymentClaim $claim, User $user, string $reason): void
    {
        $this->authorize($user);

        if (! $claim->isPending()) {
            throw ValidationException::withMessages(['claim' => 'Заяву вже опрацьовано.']);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reject_reason' => 'Вкажіть причину — її побачить агент і делікатно уточнить у клієнта.']);
        }

        DB::transaction(function () use ($claim, $user, $reason) {
            $payload = [
                'status'        => PaymentClaim::STATUS_REJECTED,
                'reject_reason' => $reason,
                'confirmed_by'  => $user->id,
                'confirmed_at'  => now(),
            ];

            $claim->update($payload);

            // Пара — це одна передача готівки. Не знайшли грошей — значить не
            // знайшли їх ні за словами клієнта, ні за звітом курʼєра.
            if ($claim->paired_claim_id) {
                PaymentClaim::whereKey($claim->paired_claim_id)
                    ->where('status', PaymentClaim::STATUS_PENDING)
                    ->update($payload);
            }
        });

        $this->webhooks->claimResolved($claim->fresh());
    }

    // -------------------------------------------------------------------------
    // Стан оплати замовлення — для агента
    // -------------------------------------------------------------------------

    /**
     * Блок payment для API: скільки внесено, скільки чекає підтвердження, борг.
     *
     * paid_amount — лише гроші, привʼязані до САМОГО замовлення. Переплату з
     * інших замовлень Client::recalculateOrderPaymentStatus() теж може зарахувати,
     * і тоді is_paid = true при меншій paid_amount. Борг у такому разі нуль:
     * агент має казати «оплачено», а не вимагати доплату за вже закрите.
     *
     * @return array<string, mixed>
     */
    public function orderPaymentState(Order $order): array
    {
        $paid = (float) Transaction::where('order_id', $order->id)
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'refund' THEN -amount ELSE 0 END), 0) AS total")
            ->value('total');

        $claims = PaymentClaim::where('order_id', $order->id)->orderByDesc('id')->get();

        // Очікування за рахунком — не заява «гроші є», у суму «чекають» не йде.
        $pendingAmount = (float) $claims
            ->where('status', PaymentClaim::STATUS_PENDING)
            ->reject(fn (PaymentClaim $c) => $c->isExpectationOnly())
            // З пари рахуємо одну заяву: це ті самі гроші.
            ->unique(fn (PaymentClaim $c) => $c->paired_claim_id ? min($c->id, $c->paired_claim_id) : $c->id)
            ->sum('amount');

        $due  = (float) ($order->final_price ?? $order->total_price);
        $debt = $order->is_paid ? 0.0 : max(0.0, round($due - $paid, 2));

        $last = $claims->first();

        return [
            'is_paid'               => (bool) $order->is_paid,
            'total'                 => $due,
            'paid_amount'           => round($paid, 2),
            'pending_claims_amount' => round($pendingAmount, 2),
            'debt'                  => $debt,
            'last_claim'            => $last ? $this->claimPayload($last) : null,
        ];
    }

    /** @return array<string, mixed> */
    public function claimPayload(PaymentClaim $claim): array
    {
        return [
            'id'            => $claim->id,
            'order_id'      => $claim->order_id,
            'source'        => $claim->source,
            'amount'        => (float) $claim->amount,
            'method'        => $claim->method,
            'status'        => $claim->status,
            'reject_reason' => $claim->reject_reason,
            'created_at'    => optional($claim->created_at)->toIso8601String(),
            'resolved_at'   => optional($claim->confirmed_at)->toIso8601String(),
        ];
    }

    // -------------------------------------------------------------------------

    public static function canResolve(?User $user): bool
    {
        return $user !== null && in_array($user->role, Transaction::PAYMENT_ROLES, true);
    }

    private function authorize(User $user): void
    {
        if (! self::canResolve($user)) {
            throw new AuthorizationException('Підтверджувати оплату може лише менеджер або власник.');
        }
    }

    private function defaultMethod(string $source): string
    {
        return in_array($source, PaymentClaim::CASH_SOURCES, true)
            ? PaymentClaim::METHOD_CASH
            : PaymentClaim::METHOD_TRANSFER;
    }

    private function comment(PaymentClaim $claim, float $amount): string
    {
        $label = PaymentClaim::sourceLabels()[$claim->source] ?? $claim->source;
        $note  = "Оплата замовлення #{$claim->order_id} · {$label} · заява #{$claim->id}";

        if (abs($amount - (float) $claim->amount) > PaymentClaim::MONEY_EPSILON) {
            $note .= ' · заявлено '.number_format((float) $claim->amount, 2, '.', ' ');
        }

        return $note;
    }
}
