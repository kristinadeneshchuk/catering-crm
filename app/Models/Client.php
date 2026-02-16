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
        'delivery_comment',
        'production_comment',
        'balance',
        'has_cutlery',
        'manager_comment',
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
            // Якщо ціна замовлення 0 (наприклад, тестове), ми його пропускаємо або позначаємо оплаченим
            if ($order->total_price <= 0) {
                if (!$order->is_paid) $order->updateQuietly(['is_paid' => true]);
                continue;
            }

            // Перевіряємо, чи вистачає грошей у "гаманці" на це замовлення
            if ($totalWallet >= $order->total_price) {
                // Грошей вистачає -> Ставимо "Оплачено"
                if (!$order->is_paid) {
                    $order->updateQuietly(['is_paid' => true]);
                }
                // Віднімаємо суму замовлення з гаманця, йдемо до наступного
                $totalWallet -= $order->total_price;
            } else {
                // Грошей НЕ вистачає -> Знімаємо "Оплачено" (якщо раптом стояло)
                if ($order->is_paid) {
                    $order->updateQuietly(['is_paid' => false]);
                }
                // Гаманець йде в мінус (для логіки циклу), грошей більше немає
                $totalWallet -= $order->total_price;
            }
        }
    }
}