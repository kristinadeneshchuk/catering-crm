<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 
        'tariff_id', 
        'project',
        'is_paid',
        'start_date', 
        'end_date',
        'duration',
        'status', 
        'calories', 
        'scale_factor', 
        'total_price', 
        'comment',
        'schedule_type',
        'delivery_time'
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'duration' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'scale_factor' => 'float',
        'total_price' => 'decimal:2',
    ];

    protected static function booted()
    {
        // 1. Розрахунок ціни перед збереженням
        static::saving(function ($order) {
            $baseKcal = 2000.0; 
            $order->scale_factor = (float)$order->calories > 0 
                ? (float)$order->calories / $baseKcal 
                : 1.0;

            $range = CalorieRange::where('min_kcal', '<=', $order->calories)
                ->where('max_kcal', '>=', $order->calories)
                ->first();

            if ($range && $order->tariff_id) {
                $tariffPrice = DB::table('tariff_prices')
                    ->where('tariff_id', $order->tariff_id)
                    ->where('calorie_range_id', $range->id)
                    ->first();

                if ($tariffPrice) {
                    $days = $order->duration ?: (Carbon::parse($order->start_date)->diffInDays($order->end_date) + 1);
                    $order->total_price = $tariffPrice->price_per_day * $days;
                }
            }
        });

        // === 2. НОВА ЛОГІКА: Вплив на баланс та Статус Оплати ===

        // Коли замовлення СТВОРЕНО
        static::created(function ($order) {
            if ($order->client_id && $order->total_price > 0) {
                // 1. Списуємо гроші з балансу
                $order->client->decrement('balance', $order->total_price);
            }
            // 2. ВАЖЛИВО: Перераховуємо статуси "Оплачено" для всіх замовлень клієнта
            if ($order->client) {
                $order->client->recalculateOrderPaymentStatus();
            }
        });

        // Коли замовлення ЗМІНЕНО
        static::updated(function ($order) {
            if ($order->client_id && $order->isDirty('total_price')) {
                $newPrice = $order->total_price;
                $oldPrice = $order->getOriginal('total_price');
                $difference = $newPrice - $oldPrice;

                // 1. Коригуємо баланс на різницю ціни
                $order->client->decrement('balance', $difference);
            }
            
            // 2. ВАЖЛИВО: Перераховуємо статуси (ціна змінилась - може щось стало неоплаченим)
            if ($order->client) {
                $order->client->recalculateOrderPaymentStatus();
            }
        });

        // Коли замовлення ВИДАЛЕНО
        static::deleted(function ($order) {
            if ($order->client_id && $order->total_price > 0) {
                // 1. Повертаємо гроші на баланс
                $order->client->increment('balance', $order->total_price);
            }

            // 2. ВАЖЛИВО: Перераховуємо статуси (гроші повернулись, може вистачить на інше замовлення)
            if ($order->client) {
                $order->client->recalculateOrderPaymentStatus();
            }
        });
    }

    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function tariff(): BelongsTo { return $this->belongsTo(Tariff::class); }

    public function getScaledMenu(): array
    {
        $k = (float)($this->scale_factor ?: 1.0);
        $period = CarbonPeriod::create($this->start_date, $this->end_date);
        $finalMenu = [];

        $cycleDays = (int) DB::table('settings')->where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            $diffInDays = abs($date->diffInDays($anchorDate));
            $globalDay = ($diffInDays % $cycleDays) + 1;

            $dailyMenu = DailyMenu::where('day_number', $globalDay)
                ->with(['menuItems.dish.dishIngredients.ingredient', 'menuItems.dish.dishIngredients.childDish'])
                ->first();

            if ($dailyMenu) {
                foreach ($dailyMenu->menuItems as $item) {
                    $dish = $item->dish;
                    if ($dish) {
                        $finalMenu[$dateStr][] = [
                            'day_of_cycle' => $globalDay,
                            'dish_name' => $dish->name,
                            'meal_type' => $item->mealType?->name ?? 'Прийом їжі',
                            'target_kcal' => round($dish->total_kcal * $k, 1),
                            'ingredients' => $this->getScaledIngredients($dish, $k),
                        ];
                    }
                }
            }
        }

        return $finalMenu;
    }
 
    private function getScaledIngredients($dish, $k, $subDishRatio = 1): array
    {
        $list = [];
        if (!$dish || !$dish->dishIngredients) return $list;

        foreach ($dish->dishIngredients as $item) {
            $currentK = $k * $subDishRatio;
            $type = mb_strtolower(trim($item->type));

            if (in_array($type, ['product', 'продукт']) && $item->ingredient) {
                $net = (float)$item->net_weight_g * $currentK;
                $yield = (float)($item->ingredient->yield_percent ?: 100);
                
                $list[] = [
                    'name' => $item->ingredient->name,
                    'net_weight' => round($net, 1),
                    'gross_weight' => round(($net * 100) / $yield, 1),
                ];
            } 
            elseif (in_array($type, ['pf', 'пф', 'напівфабрикат']) && $item->childDish) {
                $pfBaseWeight = (float)$item->childDish->base_weight_g ?: 100;
                $pfRatio = (float)$item->net_weight_g / $pfBaseWeight;
                
                $list = array_merge($list, $this->getScaledIngredients($item->childDish, $k, $pfRatio));
            }
        }
        return $list;
    }

    public function replacements()
    {
        return $this->hasMany(OrderReplacement::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function calendar()
    {
        return $this->hasOne(self::class, 'id', 'id');
    }

    public function orderDays()
    {
        return $this->hasMany(OrderDay::class);
    }
}