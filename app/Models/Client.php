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
                'delivery_comment', 'production_comment', 'balance',
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

    // === ЛОГІКА АВТОМАТИЧНОЇ ОПЛАТИ (FIFO) ===

    /**
     * Цей метод бере всі гроші, які вніс клієнт, і "гасить" ними замовлення
     * у хронологічному порядку (від старих до нових).
     */
    public function recalculateOrderPaymentStatus()
    {
        // 1. Рахуємо "Чисті гроші" (Всі оплати мінус Всі повернення)
        // Використовуємо transactions(), який ми додали вище
        $totalWallet = $this->transactions()->where('type', 'income')->sum('amount') 
                     - $this->transactions()->where('type', 'refund')->sum('amount');

        // 2. Беремо всі замовлення клієнта, починаючи з найстаріших
        $orders = $this->orders()->orderBy('start_date', 'asc')->get();

        foreach ($orders as $order) {
            // Реальна сума до сплати — завжди final_price (вже враховує знижки)
            $due = (float) $order->final_price;

            if ($due <= 0) {
                if (!$order->is_paid) $this->setOrderPaid($order, true);
                continue;
            }

            if ($totalWallet >= $due) {
                if (!$order->is_paid) {
                    $this->setOrderPaid($order, true);
                }
                $totalWallet -= $due;
            } else {
                if ($order->is_paid) {
                    $this->setOrderPaid($order, false);
                }
                $totalWallet -= $due;
            }
        }
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