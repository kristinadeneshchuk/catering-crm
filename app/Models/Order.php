<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Models\Transaction;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id', 'tariff_id', 'project', 'is_paid',
        'start_date', 'end_date', 'duration', 'status',
        'calories', 'scale_factor', 'total_price',
        'comment', 'schedule_type', 'delivery_time'
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
        /**
         * ✅ ВАЖНО:
         * Більше НЕ рахуємо scale_factor тут.
         * Бо меню різне по днях — фактор має бути денний і рахуватися "на льоту".
         *
         * Тут залишаємо тільки розрахунок ціни (як у тебе було).
         */
        static::saving(function ($order) {

            // Якщо scale_factor не заданий — тримаємо 1.0 як дефолт (для сумісності)
            if ($order->scale_factor === null) {
                $order->scale_factor = 1.0;
            }

            // --- Розрахунок ціни (Стандарт) ---
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

        static::created(function ($o) {
            self::handleBalance($o, 'sub');
            $defaultAccount = \App\Models\Account::where('is_default', true)->first();
            Transaction::create([
                'type'       => 'income',
                'category'   => 'Нове замовлення',
                'amount'     => $o->total_price ?? 0,
                'account_id' => $defaultAccount?->id,
                'date'       => now(),
                'comment'    => "Замовлення #{$o->id}" . ($o->client ? " — {$o->client->name}" : ''),
                'user_id'    => auth()->id(),
            ]);
        });

        static::updated(function ($o) {
            self::handleBalanceUpdate($o);
            if ($o->isDirty('total_price')) {
                $diff = $o->total_price - $o->getOriginal('total_price');
                if ($diff != 0) {
                    $defaultAccount = \App\Models\Account::where('is_default', true)->first();
                    Transaction::create([
                        'type'       => $diff > 0 ? 'income' : 'expense',
                        'category'   => 'Зміна замовлення',
                        'amount'     => abs($diff),
                        'account_id' => $defaultAccount?->id,
                        'date'       => now(),
                        'comment'    => "Зміна замовлення #{$o->id}" . ($o->client ? " — {$o->client->name}" : '') . ($diff > 0 ? " (+{$diff} ₴)" : " ({$diff} ₴)"),
                        'user_id'    => auth()->id(),
                    ]);
                }
            }
        });

        static::deleted(fn ($o) => self::handleBalance($o, 'add'));
    }

    // =========================
    // Баланс / оплата
    // =========================
    private static function handleBalance($order, $op)
    {
        if ($order->client_id && $order->total_price > 0) {
            $op === 'sub'
                ? $order->client->decrement('balance', $order->total_price)
                : $order->client->increment('balance', $order->total_price);
        }
        if ($order->client) $order->client->recalculateOrderPaymentStatus();
    }

    private static function handleBalanceUpdate($order)
    {
        if ($order->client_id && $order->isDirty('total_price')) {
            $diff = $order->total_price - $order->getOriginal('total_price');
            $order->client->decrement('balance', $diff);
        }
        if ($order->client) $order->client->recalculateOrderPaymentStatus();
    }

    // =========================
    // Relations
    // =========================
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function tariff(): BelongsTo { return $this->belongsTo(Tariff::class); }
    public function replacements(): HasMany { return $this->hasMany(OrderReplacement::class); }
    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function orderDays(): HasMany { return $this->hasMany(OrderDay::class); }

    // =========================================================
    // ✅ ГОЛОВНА ФУНКЦІЯ: ДЕННИЙ scale_factor (а не “раз і назавжди”)
    // =========================================================
    public function getScaleFactorForDate(Carbon $date): float
    {
        $cycleDays = (int) DB::table('settings')->where('key', 'menu_cycle_days')->value('value') ?: 24;

        // бажано теж тягнути з settings, але залишаю як у тебе, щоб не ламати логіку
        $anchorDate = Carbon::parse('2025-01-01');

        $diff = abs($date->diffInDays($anchorDate));
        $globalDay = ($diff % $cycleDays) + 1;

        $dailyMenu = DailyMenu::where('day_number', $globalDay)
            ->with(['menuItems.dish', 'menuItems.mealType'])
            ->first();

        if (!$dailyMenu) return 1.0;

        $clientMealTypeIds = $this->client?->mealTypes->pluck('id')->toArray() ?? [];
        if (empty($clientMealTypeIds)) return 1.0;

        // Рахуємо базу: суму "базових" ккал страв, які клієнт реально їсть (по прийомах їжі)
        $menuKcal = 0.0;
        $processedMeals = [];

        foreach ($dailyMenu->menuItems as $item) {
            if (!$item->dish) continue;
            if (!in_array($item->meal_type_id, $clientMealTypeIds, true)) continue;

            // Захист від дублю одного прийому їжі
            if (isset($processedMeals[$item->meal_type_id])) continue;
            $processedMeals[$item->meal_type_id] = true;

            $menuKcal += (float)($item->dish->total_kcal ?? 0);
        }

        if ($menuKcal <= 0) return 1.0;

        return round(((float)$this->calories) / $menuKcal, 4);
    }

    // =========================================================
    // Меню з масштабуванням (по дням)
    // =========================================================
    public function getScaledMenu(): array
    {
        $period = CarbonPeriod::create($this->start_date, $this->end_date);
        $finalMenu = [];

        $cycleDays = (int) DB::table('settings')->where('key', 'menu_cycle_days')->value('value') ?: 24;
        $anchorDate = Carbon::parse('2025-01-01');

        // Які прийоми їжі клієнт активні
        $clientMealTypeIds = $this->client?->mealTypes->pluck('id')->toArray() ?? [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');

            $diffInDays = abs($date->diffInDays($anchorDate));
            $globalDay = ($diffInDays % $cycleDays) + 1;

            $dailyMenu = DailyMenu::where('day_number', $globalDay)
                ->with([
                    'menuItems.mealType',
                    'menuItems.dish.dishIngredients.ingredient',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
                    'menuItems.dish.dishIngredients.childDish.dishIngredients.childDish',
                ])
                ->first();

            if (!$dailyMenu) continue;

            // ✅ Денний коефіцієнт
            $k = $this->getScaleFactorForDate($date);

            foreach ($dailyMenu->menuItems as $item) {
                $dish = $item->dish;
                if (!$dish) continue;

                if (!empty($clientMealTypeIds) && $item->meal_type_id && !in_array($item->meal_type_id, $clientMealTypeIds, true)) {
                    continue;
                }

                $finalMenu[$dateStr][] = [
                    'day_of_cycle' => $globalDay,
                    'dish_name' => $dish->name,
                    'meal_type' => $item->mealType?->name ?? 'Прийом їжі',
                    'target_kcal' => round(((float)$dish->total_kcal) * $k, 1),
                    'ingredients' => $this->getScaledIngredients($dish, $k),
                ];
            }
        }

        return $finalMenu;
    }

    // =========================================================
    // ✅ Масштаб інгредієнтів з правильною ПФ-логікою
    // =========================================================
    private function getScaledIngredients($dish, float $k, float $subDishRatio = 1.0): array
    {
        $list = [];
        if (!$dish || !$dish->dishIngredients) return $list;

        foreach ($dish->dishIngredients as $item) {
            $currentK = $k * $subDishRatio;
            $type = mb_strtolower(trim((string)($item->type ?? '')));

            // 1) Продукт
            if (in_array($type, ['product', 'продукт'], true) && $item->ingredient) {
                $net = (float)($item->net_weight_g ?? 0) * $currentK;

                $yield = (float)($item->ingredient->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;

                $list[] = [
                    'name' => $item->ingredient->name,
                    'net_weight' => round($net, 1),
                    'gross_weight' => round(($net * 100.0) / $yield, 1),
                ];
            }

            // 2) Напівфабрикат
            elseif (in_array($type, ['pf', 'пф', 'напівфабрикат', 'п/ф', 'н/ф'], true) && $item->childDish) {

                // ✅ net_weight_g тут = СКІЛЬКИ ГОТОВОГО ПФ ми кладемо у страву (вихід)
                $pfTotals = $item->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);

                if ($pfOutput <= 0) {
                    // якщо ПФ некоректний — пропускаємо, щоб не псувати математику
                    continue;
                }

                // Частка від повного виходу ПФ
                $pfRatio = ((float)($item->net_weight_g ?? 0) * $currentK) / $pfOutput;

                // Рекурсивно масштабуємо закладку всередині ПФ
                $list = array_merge($list, $this->getScaledIngredients($item->childDish, 1.0, $pfRatio));
            }
        }

        return $list;
    }

    public function calls()
    {
        return $this->hasMany(OrderCall::class);
    }

    public function projectData(): BelongsTo
    {
        // Ми пов'язуємо поле 'project' (де лежить slug) з моделлю Project
        return $this->belongsTo(Project::class, 'project', 'slug');
    }
}