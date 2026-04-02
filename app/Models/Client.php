<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Client extends Authenticatable
{
    use HasFactory, Notifiable;

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
        'manager_comment',
        'ant_comp_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

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

    public function mealTypes(): BelongsToMany
    {
        return $this->belongsToMany(MealType::class, 'client_meal_type');
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
            // Реальна сума до сплати — final_price якщо є, інакше total_price
            $due = (float) ($order->final_price > 0 ? $order->final_price : $order->total_price);

            if ($due <= 0) {
                if (!$order->is_paid) $order->updateQuietly(['is_paid' => true]);
                continue;
            }

            if ($totalWallet >= $due) {
                if (!$order->is_paid) {
                    $order->updateQuietly(['is_paid' => true]);
                }
                $totalWallet -= $due;
            } else {
                if ($order->is_paid) {
                    $order->updateQuietly(['is_paid' => false]);
                }
                $totalWallet -= $due;
            }
        }
    }

    public function calls()
    {
        return $this->hasMany(OrderCall::class);
    }
}