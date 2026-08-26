<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Client extends Authenticatable
{
    use HasFactory, Notifiable, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'name', 'phone', 'email', 'sales_source',
                'instagram_url', 'telegram_username', 'facebook_url',
                'target_kcal', 'address', 'address_entrance', 'address_apartment', 'address_floor',
                'delivery_comment', 'production_comment', 'menu_brief', 'balance',
                'has_cutlery', 'water_option', 'manager_comment', 'ant_comp_id',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('client');
    }

    protected $fillable = [
        'name',
        'phone',
        'email',
        'sales_source',
        'instagram_url',
        'telegram_username',
        'facebook_url',
        'target_kcal',
        'address',
        'address_entrance',
        'address_apartment',
        'address_floor',
        'delivery_comment',
        'production_comment',
        'menu_brief',
        'balance',
        'has_cutlery',
        'water_option',
        'manager_comment',
        'ant_comp_id',
        'cabinet_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        // Персональний токен кабінету (вхід по лінку/QR, як меню)
        static::creating(function (Client $client) {
            if (empty($client->cabinet_token)) {
                $client->cabinet_token = \Illuminate\Support\Str::random(32);
            }
        });
    }

    public function cabinetUrl(): string
    {
        return url('/cabinet/' . $this->cabinet_token);
    }

    protected $casts = [
        'balance' => 'decimal:2',
        'has_cutlery' => 'boolean',
    ];

    // === ВІДНОШЕННЯ ===

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(ClientAddress::class);
    }

    // Додаємо цей зв'язок, щоб Клієнт бачив усі свої транзакції через Замовлення
    public function transactions(): HasManyThrough
    {
        return $this->hasManyThrough(Transaction::class, Order::class);
    }

    public function ingredientExclusions(): BelongsToMany
    {
        return $this->belongsToMany(
            Ingredient::class, 
            'client_ingredient_exclusion', 
            'client_id', 
            'ingredient_id'
        )->withTimestamps();
    }

    public function dishExclusions(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'client_dish_exclusion',
            'client_id',
            'dish_id'
        )->withTimestamps();
    }

    public function replacementBundles(): BelongsToMany
    {
        return $this->belongsToMany(
            ReplacementBundle::class,
            'client_replacement_bundle',
            'client_id',
            'replacement_bundle_id'
        )->withTimestamps();
    }

    /**
     * Усі ID інгредієнтів, яких клієнт не їсть:
     * ручні `ingredientExclusions` ∪ `original_ingredient_id` з прив'язаних шаблонів.
     */
    public function effectiveExcludedIngredientIds(): array
    {
        $manual = $this->ingredientExclusions->pluck('id');

        $fromBundles = $this->replacementBundles
            ->flatMap(fn ($b) => $b->items->pluck('original_ingredient_id'));

        return $manual->merge($fromBundles)->unique()->values()->all();
    }

    public function mealTypes(): BelongsToMany
    {
        return $this->belongsToMany(MealType::class, 'client_meal_type');
    }

    // === БАЛАНС (єдине джерело правди) ===

    /**
     * Перераховує balance з нуля за формулою:
     * SUM(income) − SUM(refund) − SUM(orders.final_price).
     * Використовується замість increment/decrement, щоб поле не дрейфувало.
     */
    public function syncBalance(): void
    {
        $income     = (float) $this->transactions()->where('type', 'income')->sum('amount');
        $refund     = (float) $this->transactions()->where('type', 'refund')->sum('amount');
        $ordersCost = (float) $this->orders()->sum('final_price');

        $this->updateQuietly(['balance' => $income - $refund - $ordersCost]);
    }

    // === ЛОГІКА АВТОМАТИЧНОЇ ОПЛАТИ ===

    /** Копійчана похибка: суми decimal(10,2), порівнювати їх «в лоб» не можна. */
    private const MONEY_EPSILON = 0.001;

    /**
     * Розставляє прапорці оплати по замовленнях клієнта у два проходи.
     *
     * 1. Кожне замовлення гаситься СВОЇМИ грошима — тими транзакціями, що
     *    привязані саме до нього.
     * 2. Залишок (переплата або платіж однією сумою за кілька замовлень)
     *    перетікає на непогашені, від старих до нових.
     *
     * Раніше був чистий FIFO: увесь гаманець клієнта гасив замовлення від
     * старих до нових, ігноруючи привязку транзакції. Через це гроші, внесені
     * на конкретне замовлення, ставили галочку на іншому — менеджер бачив
     * «оплачено» там, де жодної транзакції немає. Сума боргу при цьому була
     * правильна, брехало саме розподілення.
     *
     * Баланс клієнта рахує syncBalance() окремо і від цього не залежить.
     */
    public function recalculateOrderPaymentStatus()
    {
        $orders = $this->orders()->orderBy('start_date', 'asc')->get();

        if ($orders->isEmpty()) {
            return;
        }

        $own  = $this->ownPaymentsPerOrder($orders->pluck('id'));
        $pool = 0.0;

        /** @var array<int, array{order: Order, need: float}> $pending */
        $pending = [];

        // Прохід 1 — свої гроші.
        foreach ($orders as $order) {
            $due  = (float) $order->final_price;
            $paid = (float) ($own[$order->id] ?? 0);

            if ($due <= 0) {
                // Безкоштовне замовлення (повна знижка) вважається оплаченим,
                // а гроші, якщо їх туди внесли, йдуть у спільний котел.
                $this->setOrderPaid($order, true);
                $pool += $paid;

                continue;
            }

            if ($paid + self::MONEY_EPSILON >= $due) {
                $this->setOrderPaid($order, true);
                $pool += $paid - $due;

                continue;
            }

            // Часткова оплата: те, що вже внесли, лишається за цим замовленням.
            $pending[] = ['order' => $order, 'need' => $due - $paid];
        }

        // Прохід 2 — залишок гасить решту, від старих до нових.
        foreach ($pending as $row) {
            if ($pool + self::MONEY_EPSILON >= $row['need']) {
                $this->setOrderPaid($row['order'], true);
                $pool -= $row['need'];
            } else {
                $this->setOrderPaid($row['order'], false);
            }
        }
    }

    /**
     * Скільки грошей привязано до кожного замовлення: надходження мінус повернення.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $orderIds
     * @return \Illuminate\Support\Collection<int, float>  order_id => сума
     */
    private function ownPaymentsPerOrder($orderIds)
    {
        return Transaction::whereIn('order_id', $orderIds)
            ->selectRaw("order_id, SUM(CASE WHEN type = 'income' THEN amount WHEN type = 'refund' THEN -amount ELSE 0 END) AS total")
            ->groupBy('order_id')
            ->pluck('total', 'order_id');
    }

    /**
     * Єдина точка зміни is_paid — звідси ж повідомляємо зовнішні системи.
     *
     * Оновлюємо тихо (як і раніше), щоб не тягнути за собою каскад обсерверів
     * і не зациклити перерахунок. Саме тому вебхук ставиться в чергу тут, а не
     * через модельні події: їх updateQuietly не породжує.
     */
    private function setOrderPaid(Order $order, bool $paid): void
    {
        // Нічого не змінилось — не чіпаємо БД і не шлемо подію назовні.
        // Перерахунок викликається на кожну транзакцію і кожне збереження
        // замовлення, тож без цієї перевірки Inbox отримував би шквал
        // повідомлень про «оплату», якої не було.
        if ((bool) $order->is_paid === $paid) {
            return;
        }

        $order->updateQuietly(['is_paid' => $paid]);

        app(\App\Services\Inbox\WebhookNotifier::class)->paymentStatusChanged($order, $paid);
    }

    public function calls()
    {
        return $this->hasMany(OrderCall::class);
    }

    // === МЕСЕНДЖЕРИ ===

    public function channels(): HasMany
    {
        return $this->hasMany(ClientChannel::class);
    }

    public function conversations(): HasManyThrough
    {
        return $this->hasManyThrough(
            Conversation::class,
            ClientChannel::class,
            'client_id',          // FK on client_channels
            'client_channel_id',  // FK on conversations
            'id',                 // local key on clients
            'id'                  // local key on client_channels
        );
    }
}