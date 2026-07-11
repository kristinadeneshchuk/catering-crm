<?php

namespace App\Traits;

use App\Models\DailyMenu;
use App\Models\Order;
use App\Support\DailyWeightMultiplier;
use Illuminate\Support\Collection;

trait CalculatesOrderPlan
{
    private function calculateOrderPlan(Order $order, DailyMenu $menu, ?string $date = null): array
    {
        $empty = ['items' => [], 'totals' => ['kcal' => 0, 'prot' => 0, 'fat' => 0, 'carb' => 0]];
        $weightMultiplier = DailyWeightMultiplier::for($date);

        // Індивідуальний клієнт — беремо персональне меню на дату
        if ($date && $order->menu_type === 'individual') {
            $personalDishes = $order->personalDishes()
                ->where('date', $date)
                ->with(['dish', 'mealType'])
                ->get();

            if ($personalDishes->isNotEmpty()) {
                return $this->buildPlanFromPersonal($personalDishes, $order, $weightMultiplier);
            }
        }

        $targetKcal = (float)($order->calories ?? 0);
        if ($targetKcal <= 0) return $empty;

        $clientMealTypeIds = $order->client?->mealTypes?->pluck('id')->toArray() ?? [];

        $availableItems = $menu->menuItems
            ->filter(fn($item) => $item->dish && in_array($item->meal_type_id, $clientMealTypeIds, true))
            ->sortBy(fn($item) => $item->mealType?->sort_order ?? 99)
            ->values();

        if ($availableItems->isEmpty()) return $empty;

        $allowedSortOrders = \App\Models\MealPlan::getAllowedSortOrders((int)$targetKcal);
        $selected = $availableItems->filter(
            fn($item) => in_array($item->mealType?->sort_order, $allowedSortOrders)
        )->values();

        if ($selected->isEmpty()) return $empty;

        $byMeal = $selected->groupBy('meal_type_id');

        // Нормалізація відсотків до 100% для вибраних прийомів їжі
        $rawPct = [];
        foreach ($byMeal as $mealTypeId => $items) {
            $fi = $items->first();
            $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                ? (float)$fi->custom_energy_percent
                : (float)($fi->mealType?->energy_percent ?? 0);
        }
        $totalPct   = array_sum($rawPct);
        $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

        $totals   = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $itemsOut = [];

        foreach ($byMeal as $mealTypeId => $items) {
            $firstItem = $items->first();
            $mealType  = $firstItem->mealType;

            $p        = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
            $mealKcal = ($p > 0)
                ? $targetKcal * ($p / 100.0)
                : $targetKcal * (1.0 / max(1, $byMeal->count()));

            $kcalPerDish = $mealKcal / max(1, $items->count());

            foreach ($items as $item) {
                $dish = $item->dish;
                if (!$dish) continue;

                $kcalPer100 = $this->dishKcalPer100g($dish);
                $weight     = ($kcalPer100 > 0)
                    ? (int)round(($kcalPerDish / $kcalPer100) * 100.0 * $weightMultiplier)
                    : 0;

                $dt    = $dish->calculated_totals;
                $outW  = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
                $outW  = $outW > 0 ? $outW : 1.0;

                $totals['kcal'] += $weight * $kcalPer100 / 100.0;
                $totals['prot'] += $weight * ((float)($dt['prot'] ?? 0) / $outW);
                $totals['fat']  += $weight * ((float)($dt['fat']  ?? 0) / $outW);
                $totals['carb'] += $weight * ((float)($dt['carb'] ?? 0) / $outW);

                $itemsOut[] = [
                    'dish_id'      => (int)$dish->id,
                    'meal_type_id' => (int)$mealTypeId,
                    'weight'       => $weight,
                    'meal'         => $mealType?->name ?? '-',
                    'dish'         => $dish->name,
                ];
            }
        }

        // Сортуємо за sort_order прийому їжі
        usort($itemsOut, function ($a, $b) use ($menu) {
            $aSort = $menu->menuItems->firstWhere('meal_type_id', $a['meal_type_id'])?->mealType?->sort_order ?? 99;
            $bSort = $menu->menuItems->firstWhere('meal_type_id', $b['meal_type_id'])?->mealType?->sort_order ?? 99;
            return $aSort <=> $bSort;
        });

        // Якщо у замовленні задані персональні макро-цілі (окрім ккал) —
        // додатково калібруємо грамажі під усі 4 цілі одразу.
        // Якщо цілей немає — виходимо з поточним результатом (стара логіка).
        if ($order->hasCustomMacros()) {
            [$itemsOut, $totals] = $this->rebalanceForCustomMacros(
                $itemsOut,
                $order,
                $weightMultiplier,
            );
        }

        return ['items' => $itemsOut, 'totals' => $totals];
    }

    /**
     * Перебалансовує грамажі страв так, щоб денна сума потрапила у 4 цілі:
     *   Σ(g_i · kcal_i/g)  ≈  order.calories
     *   Σ(g_i · P_i/g)      ≈  order.target_protein_g   (якщо задано)
     *   Σ(g_i · F_i/g)      ≈  order.target_fats_g       (якщо задано)
     *   Σ(g_i · C_i/g)      ≈  order.target_carbs_g      (якщо задано)
     *
     * Метод: constrained least squares (нормальні рівняння) з невід'ємністю.
     * Якщо LS видає g_i < 0 — фіксуємо його на нижній межі (30% від поточного,
     * щоб порція не зникла зовсім) і пересолюємо. Ккал важимо в 4× сильніше
     * за макро, бо це "жорсткіша" ціль (клієнт платить за раціон = за ккал).
     *
     * На вхід — вже обрахований items з "стандартним" грамажем; використовуємо
     * його як початкове наближення та як джерело "щільностей" (kcal/g на страву).
     */
    private function rebalanceForCustomMacros(array $itemsOut, Order $order, float $weightMultiplier): array
    {
        if (empty($itemsOut)) {
            return [$itemsOut, ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0]];
        }

        // Витягуємо щільності (per gram) кожної страви — з тих самих
        // calculated_totals, які використовує стара гілка.
        $densities = []; // рядок на страву: [kcal, prot, fat, carb] на 1 грам
        $baseW     = []; // початковий грамаж (з kcal-only гілки)
        foreach ($itemsOut as $idx => $it) {
            $dish = \App\Models\Dish::find($it['dish_id']);
            if (!$dish) { continue; }
            $dt   = $dish->calculated_totals;
            $outW = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
            $outW = $outW > 0 ? $outW : 1.0;

            $densities[$idx] = [
                'kcal' => $this->dishKcalPer100g($dish) / 100.0,
                'prot' => (float)($dt['prot'] ?? 0) / $outW,
                'fat'  => (float)($dt['fat']  ?? 0) / $outW,
                'carb' => (float)($dt['carb'] ?? 0) / $outW,
            ];
            $baseW[$idx] = (float) $it['weight'];
        }

        if (empty($densities)) {
            return [$itemsOut, ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0]];
        }

        // Формуємо цілі — тільки ті, які реально задані.
        // Ккал — завжди (з order.calories), тому вага 4.0.
        // Кожен заповнений макрос — вага 1.0. NULL макрос — не включаємо в LS.
        $rows = [];
        $rows[] = ['key' => 'kcal', 'target' => (float)$order->calories, 'weight' => 4.0];
        if ($order->target_protein_g !== null) {
            $rows[] = ['key' => 'prot', 'target' => (float)$order->target_protein_g, 'weight' => 1.0];
        }
        if ($order->target_fats_g !== null) {
            $rows[] = ['key' => 'fat', 'target' => (float)$order->target_fats_g, 'weight' => 1.0];
        }
        if ($order->target_carbs_g !== null) {
            $rows[] = ['key' => 'carb', 'target' => (float)$order->target_carbs_g, 'weight' => 1.0];
        }

        $indices  = array_keys($densities); // [dish_idx…]
        $n        = count($indices);
        $mRows    = count($rows);

        // Мін. грамажі (щоб порція не зникла зовсім) — 30% від початкової.
        // Макс. грамажі — 250% (щоб не було абсурдної миски).
        $minG = [];
        $maxG = [];
        foreach ($indices as $i) {
            $b = max(30.0, $baseW[$i]);      // якщо базового зовсім нема — беремо 30 г як floor.
            $minG[$i] = 0.3 * $b;
            $maxG[$i] = 2.5 * $b;
        }

        // Розв'язуємо ітеративно з активним набором (простий NNLS-lite):
        // 1) Solve WLS без обмежень на невід'ємність.
        // 2) Якщо є g_i < minG_i або > maxG_i — фіксуємо ці змінні на межі, пересолюємо решту.
        // 3) Повторюємо до збіжності (макс 10 ітерацій).
        $g = array_fill_keys($indices, null);   // null = ще не зафіксовано
        for ($iter = 0; $iter < 10; $iter++) {
            $freeIdx = array_values(array_filter($indices, fn($i) => $g[$i] === null));
            $fixedIdx = array_values(array_filter($indices, fn($i) => $g[$i] !== null));

            if (empty($freeIdx)) break; // всі зафіксовані

            // Побудова A (mRows × |free|), b (mRows), із вирахуванням внеску зафіксованих.
            $A = [];
            $b = [];
            foreach ($rows as $r) {
                $rowA = [];
                foreach ($freeIdx as $j) {
                    $rowA[] = $r['weight'] * $densities[$j][$r['key']];
                }
                $fixedContribution = 0.0;
                foreach ($fixedIdx as $j) {
                    $fixedContribution += $g[$j] * $densities[$j][$r['key']];
                }
                $A[] = $rowA;
                $b[] = $r['weight'] * ($r['target'] - $fixedContribution);
            }

            // WLS через нормальні рівняння: (Aᵀ A) x = Aᵀ b.
            $x = $this->solveLeastSquares($A, $b);
            if ($x === null) {
                // Виродженість — падаємо назад на пропорційне масштабування.
                foreach ($freeIdx as $j) { $g[$j] = $baseW[$j]; }
                break;
            }

            // Перевіряємо межі.
            $anyClamped = false;
            foreach ($freeIdx as $k => $j) {
                $val = $x[$k];
                if ($val < $minG[$j]) {
                    $g[$j] = $minG[$j];
                    $anyClamped = true;
                } elseif ($val > $maxG[$j]) {
                    $g[$j] = $maxG[$j];
                    $anyClamped = true;
                }
            }

            if (! $anyClamped) {
                // Прийняли все — фіксуємо і виходимо.
                foreach ($freeIdx as $k => $j) { $g[$j] = $x[$k]; }
                break;
            }
        }

        // Якщо чомусь щось лишилось null — беремо базовий грамаж (страховка).
        foreach ($indices as $i) {
            if ($g[$i] === null) $g[$i] = $baseW[$i];
        }

        // Перерахунок items + totals із урахуванням weight-мультиплікатора дня
        // (той самий, що застосовується в основній гілці — для сезонних поправок).
        $totals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        foreach ($itemsOut as $idx => &$it) {
            if (!isset($densities[$idx])) continue;
            $w = (int) round($g[$idx] * $weightMultiplier);
            $it['weight'] = $w;
            $d = $densities[$idx];
            $totals['kcal'] += $w * $d['kcal'];
            $totals['prot'] += $w * $d['prot'];
            $totals['fat']  += $w * $d['fat'];
            $totals['carb'] += $w * $d['carb'];
        }
        unset($it);

        return [$itemsOut, $totals];
    }

    /**
     * Найпростіше зважене LS через нормальні рівняння: розв'язує (AᵀA)·x = Aᵀb
     * гаусівським елімінуванням. A — mRows×nCols матриця, b — вектор довжини mRows.
     * Повертає масив x довжини nCols або null, якщо матриця сингулярна.
     */
    private function solveLeastSquares(array $A, array $b): ?array
    {
        $m = count($A);
        if ($m === 0) return null;
        $n = count($A[0]);
        if ($n === 0) return null;

        // AtA (n×n) і Atb (n)
        $AtA = array_fill(0, $n, array_fill(0, $n, 0.0));
        $Atb = array_fill(0, $n, 0.0);
        for ($i = 0; $i < $n; $i++) {
            for ($j = 0; $j < $n; $j++) {
                $s = 0.0;
                for ($k = 0; $k < $m; $k++) {
                    $s += $A[$k][$i] * $A[$k][$j];
                }
                $AtA[$i][$j] = $s;
            }
            $s = 0.0;
            for ($k = 0; $k < $m; $k++) {
                $s += $A[$k][$i] * $b[$k];
            }
            $Atb[$i] = $s;
        }

        // Гаусів пряме число з частковим півотингом.
        for ($col = 0; $col < $n; $col++) {
            // Пошук півота
            $piv = $col;
            $max = abs($AtA[$col][$col]);
            for ($row = $col + 1; $row < $n; $row++) {
                if (abs($AtA[$row][$col]) > $max) {
                    $max = abs($AtA[$row][$col]);
                    $piv = $row;
                }
            }
            if ($max < 1e-10) return null; // сингулярна
            if ($piv !== $col) {
                [$AtA[$col], $AtA[$piv]] = [$AtA[$piv], $AtA[$col]];
                [$Atb[$col], $Atb[$piv]] = [$Atb[$piv], $Atb[$col]];
            }
            $pivVal = $AtA[$col][$col];
            for ($row = $col + 1; $row < $n; $row++) {
                $factor = $AtA[$row][$col] / $pivVal;
                if ($factor === 0.0) continue;
                for ($k = $col; $k < $n; $k++) {
                    $AtA[$row][$k] -= $factor * $AtA[$col][$k];
                }
                $Atb[$row] -= $factor * $Atb[$col];
            }
        }

        // Зворотній хід
        $x = array_fill(0, $n, 0.0);
        for ($row = $n - 1; $row >= 0; $row--) {
            $s = $Atb[$row];
            for ($k = $row + 1; $k < $n; $k++) {
                $s -= $AtA[$row][$k] * $x[$k];
            }
            if (abs($AtA[$row][$row]) < 1e-10) return null;
            $x[$row] = $s / $AtA[$row][$row];
        }
        return $x;
    }

    // Будуємо план з персонального меню (для індивідуальних клієнтів)
    private function buildPlanFromPersonal(Collection $personalDishes, Order $order, float $weightMultiplier = 1.0): array
    {
        $targetKcal = (float)($order->calories ?? 0);
        $count      = $personalDishes->count();
        $totals     = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];
        $itemsOut   = [];

        foreach ($personalDishes as $pd) {
            $dish     = $pd->dish;
            $mealType = $pd->mealType;
            if (!$dish) continue;

            $kcalPer100 = $this->dishKcalPer100g($dish);

            // Якщо вага задана вручну — використовуємо її, інакше розраховуємо по калоріям
            if ($pd->weight_grams) {
                $weight = (int)round((int)$pd->weight_grams * $weightMultiplier);
            } else {
                $kcalForMeal = $count > 0 ? $targetKcal / $count : 0;
                $weight      = ($kcalPer100 > 0)
                    ? (int)round(($kcalForMeal / $kcalPer100) * 100.0 * $weightMultiplier)
                    : 0;
            }

            $dt   = $dish->calculated_totals;
            $outW = (float)($dt['output_weight'] ?? ($dish->base_weight_g ?? 0));
            $outW = $outW > 0 ? $outW : 1.0;

            $totals['kcal'] += $weight * $kcalPer100 / 100.0;
            $totals['prot'] += $weight * ((float)($dt['prot'] ?? 0) / $outW);
            $totals['fat']  += $weight * ((float)($dt['fat']  ?? 0) / $outW);
            $totals['carb'] += $weight * ((float)($dt['carb'] ?? 0) / $outW);

            $itemsOut[] = [
                'dish_id'      => (int)$dish->id,
                'meal_type_id' => (int)$pd->meal_type_id,
                'weight'       => $weight,
                'meal'         => $mealType?->name ?? '-',
                'dish'         => $dish->name,
            ];
        }

        return ['items' => $itemsOut, 'totals' => $totals];
    }

    private function dishKcalPer100g($dish): float
    {
        $baseW     = (float)($dish->base_weight_g ?? 0);
        $totalKcal = (float)($dish->total_kcal ?? 0);
        if ($baseW <= 0 || $totalKcal <= 0) return 0.0;
        return ($totalKcal / $baseW) * 100.0;
    }
}
