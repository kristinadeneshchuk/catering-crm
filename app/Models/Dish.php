<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dish extends Model
{
    protected $guarded = [];

    public function dishIngredients(): HasMany
    {
        return $this->hasMany(DishIngredient::class);
    }

    /**
     * ✅ Повертає:
     * - input_weight  = сума закладки (Σ net_weight_g інгредієнтів цього рівня)
     * - output_weight = вихід після приготування (base_weight_g), якщо задано; інакше = input_weight
     * - prot/fat/carb/cost по інгредієнтах (з урахуванням yield% продуктів)
     * - kcal по формулі 4-9-4
     */
    public function getCalculatedTotalsAttribute(): array
    {
        $totals = [
            'kcal' => 0.0,
            'prot' => 0.0,
            'fat'  => 0.0,
            'carb' => 0.0,
            'cost' => 0.0,

            // важливо розрізняти:
            'input_weight'  => 0.0, // закладка
            'output_weight' => 0.0, // вихід
        ];

        // 1) input_weight = сума закладки на цьому рівні (те, що ти реально вносиш у ПФ/страву)
        foreach ($this->dishIngredients as $item) {
            $totals['input_weight'] += (float)($item->net_weight_g ?? 0);
        }

        // 2) output_weight = вихід (після готування). Для ПФ це критично.
        $base = (float)($this->base_weight_g ?? 0);
        $totals['output_weight'] = $base > 0 ? $base : $totals['input_weight'];

        // 3) БЖВ/ціна
        foreach ($this->dishIngredients as $item) {
            $type = mb_strtolower(trim((string)($item->type ?? '')));

            $netWeight = (float)($item->net_weight_g ?? 0);
            if ($netWeight <= 0) continue;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf      = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($isProduct && $item->ingredient) {
                $ing = $item->ingredient;

                // yield% продукту (очищення/обробка)
                $yield = (float)($ing->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;

                // брутто для ціни
                $grossWeight = ($netWeight * 100.0) / $yield;

                // ціна
                $pricePerKg = (float)($ing->price_per_kg ?? 0);
                $totals['cost'] += ($pricePerKg / 1000.0) * $grossWeight;

                // БЖВ з довідника на 100г нетто
                $totals['prot'] += ((float)($ing->proteins_100g ?? 0) * $netWeight / 100.0);
                $totals['fat']  += ((float)($ing->fats_100g ?? 0)     * $netWeight / 100.0);
                $totals['carb'] += ((float)($ing->carbs_100g ?? 0)    * $netWeight / 100.0);

            } elseif ($isPf && $item->childDish) {
                $pf = $item->childDish;

                // totals ПФ (рекурсивно)
                $pfTotals = $pf->calculated_totals;

                // ✅ Важливо: net_weight_g у DishIngredient для ПФ — це СКІЛЬКИ ГОТОВОГО ПФ (output) ти кладеш у страву.
                // Тому частка = netWeight / pf.output_weight
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);

                if ($pfOutput <= 0) {
                    // якщо ПФ некоректний — краще пропустити, ніж "ділити на 1" і ламати математику
                    continue;
                }

                $ratio = $netWeight / $pfOutput;

                $totals['prot'] += ((float)($pfTotals['prot'] ?? 0) * $ratio);
                $totals['fat']  += ((float)($pfTotals['fat']  ?? 0) * $ratio);
                $totals['carb'] += ((float)($pfTotals['carb'] ?? 0) * $ratio);
                $totals['cost'] += ((float)($pfTotals['cost'] ?? 0) * $ratio);
            } else {
                // якщо тип не розпізнано — можна нічого не робити
                continue;
            }
        }

        // 4) Калорії строго по 4-9-4 (виходячи з БЖВ)
        $totals['kcal'] = ($totals['prot'] * 4.0) + ($totals['fat'] * 9.0) + ($totals['carb'] * 4.0);

        // 5) Для сумісності зі старим кодом:
        // weight = ВИХІД (output), бо саме це має сенс як "вага страви"
        $totals['weight'] = $totals['output_weight'];

        return $totals;
    }

    // =========================
    // Аксесори для Filament
    // =========================
    public function getTotalKcalAttribute(): float { return round((float)($this->calculated_totals['kcal'] ?? 0), 1); }
    public function getTotalProtAttribute(): float { return round((float)($this->calculated_totals['prot'] ?? 0), 1); }
    public function getTotalFatAttribute(): float  { return round((float)($this->calculated_totals['fat']  ?? 0), 1); }
    public function getTotalCarbAttribute(): float { return round((float)($this->calculated_totals['carb'] ?? 0), 1); }
    public function getTotalCostAttribute(): float { return round((float)($this->calculated_totals['cost'] ?? 0), 2); }

    // (опційно) корисно для дебагу / виводу
    public function getInputWeightAttribute(): float
    {
        return round((float)($this->calculated_totals['input_weight'] ?? 0), 1);
    }

    public function getOutputWeightAttribute(): float
    {
        return round((float)($this->calculated_totals['output_weight'] ?? 0), 1);
    }
}