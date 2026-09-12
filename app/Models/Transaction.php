<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    // 🔥 ДОДАНО employee_id
    protected $fillable = [
        'order_id',
        // client_id і method були в таблиці, але не тут — тож create() їх
        // мовчки відкидав: оплата йшла без клієнта і без способу оплати.
        'client_id',
        'method',
        'employee_id',
        'stock_document_id',
        'amount',
        'account_id',
        'type',
        'category',
        'date',
        'comment',
        'user_id'
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    /** Хто має право внести гроші клієнта в касу. */
    public const PAYMENT_ROLES = [User::ROLE_ADMIN, User::ROLE_MANAGER];

    /**
     * Надходження, привʼязане до замовлення, — це і є оплата: саме такі
     * транзакції рахує Client::recalculateOrderPaymentStatus() і ставить is_paid.
     *
     * Записи «Нове замовлення» / «Зміна замовлення», які пише Order.php, теж
     * мають тип income, але order_id у них немає — це журнал нарахувань, а не
     * гроші. Тому ознака — саме пара «income + order_id».
     */
    public function isClientPayment(): bool
    {
        return $this->type === 'income' && $this->order_id !== null;
    }

    // === ЛОГІКА БАЛАНСУ ТА АВТО-ОПЛАТИ ===
    protected static function booted()
    {
        // Єдиний запобіжник на всі шляхи, якими в CRM зʼявляються гроші клієнта.
        //
        // Шляхів пʼять: «Оплата» в картці замовлення, «Нова оплата» у вкладці
        // транзакцій, форма «Журнал транзакцій», кнопка в чаті Inbox і
        // підтвердження заяви. Ловити кожен у своєму місці — значить колись
        // пропустити шостий. Тож правило живе тут, де його не обійти.
        static::creating(function (Transaction $t) {
            if (! $t->isClientPayment()) {
                return;
            }

            // Без каси гроші потрапляли в is_paid, але не в касу: саме так
            // працювала кнопка в чаті, і каса розходилась із фактом.
            if (! $t->account_id) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'account_id' => 'Оберіть касу, куди прийшли гроші.',
                ]);
            }

            // Оплату підтверджує лише людина з правом на касу. Кухар до цього
            // не доходить через інтерфейс, але правило має тримати й код.
            $user = auth()->user();

            if ($user && ! in_array($user->role, self::PAYMENT_ROLES, true)) {
                throw new \Illuminate\Auth\Access\AuthorizationException(
                    'Підтверджувати оплату може лише менеджер або власник.'
                );
            }

            // Клієнта беремо з замовлення, щоб поле не лишалось порожнім.
            if (! $t->client_id && $t->order_id) {
                $t->client_id = Order::whereKey($t->order_id)->value('client_id');
            }
        });

        // Будь-яка зміна транзакції клієнта → перерахувати баланс і FIFO статуси.
        // Не використовуємо increment/decrement, щоб поле balance не дрейфувало —
        // syncBalance() завжди обчислює з нуля за формулою.
        $syncClient = function ($transaction) {
            if ($transaction->order && $transaction->order->client) {
                $transaction->order->client->syncBalance();
                $transaction->order->client->recalculateOrderPaymentStatus();
            }
        };

        static::created(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Виплата ЗП: списуємо з "Боргу компанії" тут (єдина точка), а не в
            // кнопках "Виплатити" — щоб транзакція, створена будь-де (кнопка,
            // Журнал транзакцій, код), однаково рухала баланс співробітника.
            if ($transaction->employee_id && $transaction->type === 'expense') {
                $transaction->employee()->decrement('balance', abs((float) $transaction->amount));
            }
        });

        static::updated(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Правка виплати ЗП (сума / співробітник / тип) — відкатуємо стару
            // й застосовуємо нову, інакше баланс дрейфує (так і з'явився борг,
            // якого ніхто не нараховував).
            if ($transaction->wasChanged(['amount', 'employee_id', 'type'])) {
                $oldEmp  = $transaction->getOriginal('employee_id');
                $oldAmt  = abs((float) $transaction->getOriginal('amount'));
                $oldType = $transaction->getOriginal('type');

                if ($oldEmp && $oldType === 'expense') {
                    Employee::find($oldEmp)?->increment('balance', $oldAmt);
                }
                if ($transaction->employee_id && $transaction->type === 'expense') {
                    $transaction->employee()->decrement('balance', abs((float) $transaction->amount));
                }
            }
        });

        static::deleted(function ($transaction) use ($syncClient) {
            $syncClient($transaction);

            // Якщо це була виплата зарплати (співробітник) — повертаємо суму у "Борг компанії"
            if ($transaction->employee_id && $transaction->type === 'expense') {
                $transaction->employee()->increment('balance', abs((float) $transaction->amount));
            }
        });
    }
}