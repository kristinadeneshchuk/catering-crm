<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use App\Models\Transaction;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Order extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'client_id', 'parent_order_id', 'tariff_id', 'project', 'is_paid',
                'start_date', 'end_date', 'duration', 'status',
                'calories', 'target_protein_g', 'target_fats_g', 'target_carbs_g',
                'price_per_day', 'total_price',
                'comment', 'schedule_type', 'menu_type', 'menu_plan_id', 'delivery_time',
                'discount_type', 'discount_value', 'discount_reason',
                'discount_amount', 'final_price',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('order');
    }

    protected $fillable = [
        'client_id', 'parent_order_id', 'tariff_id', 'project', 'is_paid',
        'start_date', 'end_date', 'duration', 'status',
        'calories', 'target_protein_g', 'target_fats_g', 'target_carbs_g',
        'scale_factor', 'price_per_day', 'total_price',
        'comment', 'menu_token', 'schedule_type', 'menu_type', 'menu_plan_id', 'delivery_time',
        'discount_type', 'discount_value', 'discount_reason',
        'discount_amount', 'final_price',
        'reward_unlocked', 'reward_given',
    ];

    protected $casts = [
        'is_paid'          => 'boolean',
        'reward_unlocked'  => 'boolean',
        'reward_given'     => 'boolean',
        'duration'         => 'integer',
        'start_date'       => 'date',
        'end_date'         => 'date',
        'scale_factor'     => 'float',
        'target_protein_g' => 'integer',
        'target_fats_g'    => 'integer',
        'target_carbs_g'   => 'integer',
        'total_price'      => 'decimal:2',
        'discount_value'   => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'final_price'      => 'decimal:2',
    ];

    /**
     * Клієнт задав хоча б одну макро-ціль (окрім ккал) → замовлення
     * потрапляє в «індивідуальний профіль КБЖУ»: грамажі перерахуються
     * під усі 4 цілі, а не тільки під калораж.
     */
    public function hasCustomMacros(): bool
    {
        return $this->target_protein_g !== null
            || $this->target_fats_g    !== null
            || $this->target_carbs_g   !== null;
    }

    /**
     * Аналізує, наскільки день доставки задовольняє задані макро-цілі,
     * і якщо якась ціль недосяжна (відхилення >15%) — повертає список
     * страв, які найбільше «шкодять» цій цілі. Використовується банером
     * на картці замовлення в OrderResource.
     *
     * @param  string|null  $date  Дата доставки для перевірки (default — завтра).
     * @return array{
     *   items: array<int, array{
     *     macro: string,
     *     label: string,
     *     target: int,
     *     actual: int,
     *     direction: string,
     *     offenders: array<int, array{name: string, contribution: float, per_100g: float}>
     *   }>,
     *   date: string,
     *   day_number: int|null
     * }
     */
    public function analyzeMacroTargets(?string $date = null): array
    {
        $out = ['items' => [], 'date' => '', 'day_number' => null, 'fallback_triggered' => false];
        if (! $this->hasCustomMacros()) return $out;
        if ($this->menu_type !== 'cyclic') return $out;

        $date = $date ?: \Carbon\Carbon::now()->addDay()->format('Y-m-d');
        $out['date'] = $date;

        $planModel = \App\Models\MenuPlan::find($this->menu_plan_id);
        if (! $planModel) return $out;

        $dayNumber = $planModel->globalDayFor($date);
        $out['day_number'] = $dayNumber;

        $menu = \App\Models\DailyMenu::where('menu_plan_id', $this->menu_plan_id)
            ->where('day_number', $dayNumber)
            ->with(['menuItems.dish', 'menuItems.mealType'])
            ->first();
        if (! $menu) return $out;

        // Клієнт із його прийомами їжі — інакше calculateOrderPlan поверне порожньо.
        $this->loadMissing('client.mealTypes');
        if (! $this->client) return $out;

        // Виклик trait через анонімний клас, щоб не тягти trait у модель
        // (модель має бути «тонкою», без бізнес-логіки перерахунку).
        $calc = new class {
            use \App\Traits\CalculatesOrderPlan {
                calculateOrderPlan as public;
            }
        };
        $plan = $calc->calculateOrderPlan($this, $menu, $date);
        if (empty($plan['items'])) return $out;

        $out['fallback_triggered'] = (bool) ($plan['fallback_triggered'] ?? false);

        $targets = [
            'prot' => ['value' => $this->target_protein_g, 'label' => 'Білки'],
            'fat'  => ['value' => $this->target_fats_g,    'label' => 'Жири'],
            'carb' => ['value' => $this->target_carbs_g,   'label' => 'Вуглеводи'],
        ];

        $dishIds = array_column($plan['items'], 'dish_id');
        $dishes  = \App\Models\Dish::whereIn('id', $dishIds)->get()->keyBy('id');

        foreach ($targets as $key => $info) {
            if ($info['value'] === null) continue;

            $actual = (int) round($plan['totals'][$key] ?? 0);
            $target = (int) $info['value'];
            if ($target <= 0) continue;

            $devPct = abs($actual - $target) / $target * 100;
            if ($devPct < 15.0) continue; // ціль вважаємо досягнутою

            $direction = $actual > $target ? 'too_much' : 'too_little';

            // Внески страв у цю макро-групу (grams of macro from each dish в порції).
            $contributions = [];
            foreach ($plan['items'] as $it) {
                $dish = $dishes->get($it['dish_id']);
                if (! $dish) continue;
                $baseW = (float) ($dish->base_weight_g ?? 0);
                if ($baseW <= 0) continue;

                $macroTotalField = 'total_' . $key; // total_prot / total_fat / total_carb
                $perG    = ((float) ($dish->{$macroTotalField} ?? 0)) / $baseW;
                $contrib = $perG * (int) $it['weight'];

                $contributions[] = [
                    'name'         => $dish->name,
                    'contribution' => round($contrib, 1),
                    'per_100g'     => round($perG * 100.0, 1),
                ];
            }

            // Сортуємо: якщо забагато — найбільші вклади вгору (їх треба зменшити/свапнути);
            // якщо замало — найменші вгору (вони не додають цієї макро, кандидати на свап
            // на страву, багату на цю макро).
            usort($contributions, function ($a, $b) use ($direction) {
                return $direction === 'too_much'
                    ? $b['contribution'] <=> $a['contribution']
                    : $a['contribution'] <=> $b['contribution'];
            });

            $out['items'][] = [
                'macro'     => $key,
                'label'     => $info['label'],
                'target'    => $target,
                'actual'    => $actual,
                'direction' => $direction,
                'offenders' => array_slice($contributions, 0, 2),
            ];
        }

        return $out;
    }

    protected static function booted()
    {
        static::creating(function ($order) {
            if (empty($order->menu_token)) {
                $order->menu_token = bin2hex(random_bytes(16));
            }
        });

        static::saving(function ($order) {
            if ($order->scale_factor === null) {
                $order->scale_factor = 1.0;
            }

            // --- Авто-заповнення menu_plan_id ---
            // Циклічне меню без явного плану: беремо дефолтний план тарифу;
            // якщо й там пусто — системний дефолтний план.
            // Індивідуальні (individual) меню — план не потрібен, лишаємо null.
            if ($order->menu_type !== 'individual' && empty($order->menu_plan_id)) {
                $planId = null;
                if ($order->tariff_id) {
                    $planId = DB::table('tariffs')->where('id', $order->tariff_id)->value('default_menu_plan_id');
                }
                if (!$planId) {
                    $planId = optional(MenuPlan::default())->id;
                }
                if ($planId) {
                    $order->menu_plan_id = $planId;
                }
            }

            // --- Розрахунок базової ціни ---
            // Ціна заморожена з моменту створення замовлення: для існуючих замовлень
            // НІКОЛИ не перераховуємо price_per_day з tariff_prices, навіть якщо
            // менеджер змінив тариф/калораж/тривалість. Це гарантує, що підняття
            // цін у tariff_prices не зачіпає вже оформлені замовлення.
            // Для нових замовлень — беремо актуальну ціну з тарифу.
            if (!$order->exists || $order->price_per_day === null) {
                $range = CalorieRange::where('min_kcal', '<=', $order->calories)
                    ->where('max_kcal', '>=', $order->calories)
                    ->first();

                if ($range && $order->tariff_id) {
                    $tariffPrice = DB::table('tariff_prices')
                        ->where('tariff_id', $order->tariff_id)
                        ->where('calorie_range_id', $range->id)
                        ->first();

                    if ($tariffPrice) {
                        $order->price_per_day = (float) $tariffPrice->price_per_day;
                    }
                }
            } elseif ($order->isDirty('price_per_day')) {
                // Existing order — будь-яке намагання змінити price_per_day з форми
                // відкочуємо назад до зафіксованої ціни з БД.
                $order->price_per_day = $order->getOriginal('price_per_day');
            }

            if ($order->price_per_day > 0) {
                $days = $order->duration ?: (Carbon::parse($order->start_date)->diffInDays($order->end_date) + 1);
                $order->total_price = $order->price_per_day * $days;
            }

            // --- Розрахунок знижки рівня замовлення ---
            $order->discount_amount = $order->calculateOrderDiscount();

            // --- final_price = total_price - order discount - знижки по днях ---
            $dayDiscounts = $order->exists
                ? (float) DB::table('order_days')
                    ->where('order_id', $order->id)
                    ->sum('discount_amount')
                : 0;

            $order->final_price = max(0, (float) $order->total_price - (float) $order->discount_amount - $dayDiscounts);
        });

        static::created(function ($o) {
            self::syncClient($o);
            Transaction::create([
                'type'     => 'income',
                'category' => 'Нове замовлення',
                'amount'   => $o->final_price ?? $o->total_price ?? 0,
                'date'     => now(),
                'comment'  => "Замовлення #{$o->id}" . ($o->client ? " — {$o->client->name}" : ''),
                'user_id'  => auth()->id(),
            ]);
        });

        static::updated(function ($o) {
            self::syncClient($o);

            if ($o->isDirty('final_price')) {
                $diff = (float) $o->final_price - (float) $o->getOriginal('final_price');
                if (abs($diff) > 0.001) {
                    $clientName = $o->client ? " — {$o->client->name}" : '';
                    $absDiff    = abs(round($diff, 2));
                    $sign       = $diff > 0 ? '+' : '-';

                    // Визначаємо категорію та опис транзакції
                    if ($o->isDirty(['discount_type', 'discount_value'])) {
                        $category = $diff < 0 ? 'Знижка' : 'Скасування знижки';
                        $action   = $diff < 0 ? 'Знижка застосована' : 'Знижка скасована';
                    } elseif ($o->isDirty('duration')) {
                        $daysDiff = (int) $o->duration - (int) $o->getOriginal('duration');
                        $category = 'Зміна замовлення';
                        $action   = $daysDiff > 0
                            ? 'Додано ' . abs($daysDiff) . ' дн.'
                            : 'Прибрано ' . abs($daysDiff) . ' дн.';
                    } elseif ($o->isDirty(['start_date', 'end_date'])) {
                        $category = 'Зміна замовлення';
                        $action   = $diff > 0 ? 'Додано днів' : 'Прибрано днів';
                    } else {
                        $category = 'Зміна замовлення';
                        $action   = 'Зміна ціни';
                    }

                    Transaction::create([
                        'type'     => $diff > 0 ? 'income' : 'expense',
                        'category' => $category,
                        'amount'   => $absDiff,
                        'date'     => now(),
                        'comment'  => "{$action}{$clientName} ({$sign}{$absDiff} ₴), замовлення #{$o->id}",
                        'user_id'  => auth()->id(),
                    ]);
                }
            }
        });

        static::deleted(fn ($o) => self::syncClient($o));
    }

    // =========================
    // Баланс / оплата
    // =========================

    /**
     * Перераховує баланс клієнта з джерела правди (transactions + orders.final_price)
     * та оновлює FIFO-статуси оплати замовлень.
     * Викликається після будь-якої зміни замовлення замість incrementing/decrementing.
     */
    private static function syncClient($order)
    {
        if ($order->client) {
            $order->client->syncBalance();
            $order->client->recalculateOrderPaymentStatus();
        }
    }

    // =========================
    // Знижки
    // =========================

    /**
     * Розраховує суму знижки рівня замовлення (без днів).
     */
    public function calculateOrderDiscount(): float
    {
        if (!$this->discount_type || !$this->discount_value || (float) $this->discount_value <= 0) {
            return 0;
        }

        return match ($this->discount_type) {
            'percent' => round((float) $this->total_price * (float) $this->discount_value / 100, 2),
            'fixed'   => min((float) $this->discount_value, (float) $this->total_price),
            default   => 0,
        };
    }

    /**
     * Перераховує final_price з урахуванням знижок на дні.
     * Викликається з OrderDay після зміни знижки на день.
     */
    public function syncFinalPrice(): void
    {
        $dayDiscounts = (float) DB::table('order_days')
            ->where('order_id', $this->id)
            ->sum('discount_amount');

        $newFinalPrice = max(0, (float) $this->total_price - (float) $this->discount_amount - $dayDiscounts);

        if (abs($newFinalPrice - (float) $this->final_price) > 0.001) {
            $this->update(['final_price' => $newFinalPrice]);
        }
    }

    // =========================
    // Статус (єдине джерело правди)
    // =========================

    /**
     * Підтягує end_date з реального MAX(order_days.date).
     * Викликається з OrderDayObserver на створення/зміну/видалення дня.
     */
    public function recomputeEndDate(): void
    {
        $maxDate = $this->orderDays()->max('date');
        if (!$maxDate) {
            return;
        }

        $newEnd = Carbon::parse($maxDate)->toDateString();
        $currentEnd = $this->end_date ? Carbon::parse($this->end_date)->toDateString() : null;

        if ($currentEnd !== $newEnd) {
            $this->update(['end_date' => $newEnd]);
        }
    }

    /**
     * Перераховує статус замовлення з реальних order_days.
     *
     * Правила:
     *  - 'paused' — sticky: ручне рішення адміна, не чіпається.
     *  - Немає днів з date >= today → 'finished' (якщо ще не finished/completed).
     *  - Є майбутні дні → 'new' (єдине замовлення клієнта) або 'active'.
     *    Перетираємо лише з finished/completed/порожнього — щоб не плутати new ↔ active.
     */
    public function recomputeStatus(): void
    {
        if ($this->status === 'paused') {
            return;
        }

        $hasFuture = $this->orderDays()->whereDate('date', '>=', now())->exists();

        if (!$hasFuture) {
            if (!in_array($this->status, ['finished', 'completed'], true)) {
                $this->update(['status' => 'finished']);
            }
            return;
        }

        $clientOrdersCount = static::where('client_id', $this->client_id)->count();
        $target = $clientOrdersCount === 1 ? 'new' : 'active';

        $resurrectFrom = ['finished', 'completed', null, ''];

        if (in_array($this->status, $resurrectFrom, true) && $this->status !== $target) {
            $this->update(['status' => $target]);
        }
    }

    // =========================
    // Relations
    // =========================
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function tariff(): BelongsTo { return $this->belongsTo(Tariff::class); }
    public function replacements(): HasMany { return $this->hasMany(OrderReplacement::class); }

    /**
     * Виключення інгредієнтів, які додані тільки для цього замовлення.
     * Додаються поверх клієнтських — не заміняють їх.
     */
    public function ingredientExclusions(): BelongsToMany
    {
        return $this->belongsToMany(
            Ingredient::class,
            'order_ingredient_exclusion',
            'order_id',
            'ingredient_id'
        )->withTimestamps();
    }

    /**
     * Усі інгредієнти, які треба виключити з цього замовлення:
     * клієнтські ручні ∪ original_ingredient_id з клієнтських бандлів ∪ ордерські ручні.
     * Повертає колекцію Ingredient (для `->contains('id', $x)`).
     */
    public function effectiveExcludedIngredients(): Collection
    {
        $client = $this->client;

        $clientManual = $client?->ingredientExclusions ?? collect();
        $clientBundle = ($client?->replacementBundles ?? collect())
            ->flatMap(fn ($b) => $b->items->map(fn ($i) => $i->originalIngredient))
            ->filter();
        $orderManual = $this->ingredientExclusions ?? collect();

        return $clientManual
            ->merge($clientBundle)
            ->merge($orderManual)
            ->unique('id')
            ->values();
    }

    public function effectiveExcludedIngredientIds(): array
    {
        return $this->effectiveExcludedIngredients()->pluck('id')->all();
    }

    public function transactions(): HasMany { return $this->hasMany(Transaction::class); }
    public function orderDays(): HasMany { return $this->hasMany(OrderDay::class); }
    public function personalDishes(): HasMany { return $this->hasMany(OrderDayDish::class); }

    // Сімейні замовлення: батьківський та дочірні раціони
    public function parentOrder(): BelongsTo { return $this->belongsTo(Order::class, 'parent_order_id'); }
    public function childOrders(): HasMany { return $this->hasMany(Order::class, 'parent_order_id'); }

    public function isIndividual(): bool { return $this->menu_type === 'individual'; }

    public function menuPlan(): BelongsTo { return $this->belongsTo(MenuPlan::class); }

    /**
     * План меню для розрахунків. Якщо у замовлення явно не вказаний — повертає дефолтний.
     */
    public function effectiveMenuPlan(): ?MenuPlan
    {
        return $this->menuPlan ?? MenuPlan::default();
    }

    public function dishRatings(): HasMany { return $this->hasMany(DishRating::class); }

    // =========================================================
    // ✅ ГОЛОВНА ФУНКЦІЯ: ДЕННИЙ scale_factor (а не “раз і назавжди”)
    // =========================================================
    public function getScaleFactorForDate(Carbon $date): float
    {
        $plan = $this->effectiveMenuPlan();
        if (!$plan) return 1.0;

        $globalDay = $plan->globalDayFor($date);

        $dailyMenu = DailyMenu::where('menu_plan_id', $plan->id)
            ->where('day_number', $globalDay)
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

        $plan = $this->effectiveMenuPlan();
        if (!$plan) return $finalMenu;

        // Які прийоми їжі клієнт активні
        $clientMealTypeIds = $this->client?->mealTypes->pluck('id')->toArray() ?? [];

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');

            $globalDay = $plan->globalDayFor($date);

            $dailyMenu = DailyMenu::where('menu_plan_id', $plan->id)
                ->where('day_number', $globalDay)
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