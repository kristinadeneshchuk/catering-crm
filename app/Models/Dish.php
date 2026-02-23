<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dish extends Model
{
    protected $guarded = [];

    // 🔥 Змінна для кешування результатів розрахунку в пам'яті
    protected ?array $memoizedTotals = null;

    public function dishIngredients(): HasMany
    {
        return $this->hasMany(DishIngredient::class);
    }

    public function getCalculatedTotalsAttribute(): array
    {
        // ✅ Якщо ми вже рахували цю страву під час поточного запиту — повертаємо результат миттєво
        if ($this->memoizedTotals !== null) {
            return $this->memoizedTotals;
        }

        $totals = [
            'kcal' => 0.0,
            'prot' => 0.0,
            'fat'  => 0.0,
            'carb' => 0.0,
            'cost' => 0.0,
            'input_weight'  => 0.0,
            'output_weight' => 0.0,
        ];

        foreach ($this->dishIngredients as $item) {
            $totals['input_weight'] += (float)($item->net_weight_g ?? 0);
        }

        $base = (float)($this->base_weight_g ?? 0);
        $totals['output_weight'] = $base > 0 ? $base : $totals['input_weight'];

        foreach ($this->dishIngredients as $item) {
            $type = mb_strtolower(trim((string)($item->type ?? '')));
            $netWeight = (float)($item->net_weight_g ?? 0);
            if ($netWeight <= 0) continue;

            $isProduct = in_array($type, ['product', 'продукт'], true);
            $isPf      = in_array($type, ['pf', 'напівфабрикат', 'п/ф', 'н/ф'], true);

            if ($isProduct && $item->ingredient) {
                $ing = $item->ingredient;
                $yield = (float)($ing->yield_percent ?: 100);
                if ($yield <= 0) $yield = 100;

                $grossWeight = ($netWeight * 100.0) / $yield;
                
                $avgPrice = (float)($ing->average_price ?? 0);
                $basePrice = (float)($ing->price_per_kg ?? 0);
                $pricePerKg = $avgPrice > 0 ? $avgPrice : $basePrice;
                
                $totals['cost'] += ($pricePerKg / 1000.0) * $grossWeight;

                $totals['prot'] += ((float)($ing->proteins_100g ?? 0) * $netWeight / 100.0);
                $totals['fat']  += ((float)($ing->fats_100g ?? 0)     * $netWeight / 100.0);
                $totals['carb'] += ((float)($ing->carbs_100g ?? 0)    * $netWeight / 100.0);

            } elseif ($isPf && $item->childDish) {
                $pfTotals = $item->childDish->calculated_totals;
                $pfOutput = (float)($pfTotals['output_weight'] ?? 0);

                if ($pfOutput > 0) {
                    $ratio = $netWeight / $pfOutput;
                    $totals['prot'] += ((float)($pfTotals['prot'] ?? 0) * $ratio);
                    $totals['fat']  += ((float)($pfTotals['fat']  ?? 0) * $ratio);
                    $totals['carb'] += ((float)($pfTotals['carb'] ?? 0) * $ratio);
                    $totals['cost'] += ((float)($pfTotals['cost'] ?? 0) * $ratio);
                }
            }
        }

        $totals['kcal'] = ($totals['prot'] * 4.0) + ($totals['fat'] * 9.0) + ($totals['carb'] * 4.0);
        $totals['weight'] = $totals['output_weight'];

        // ✅ Зберігаємо в кеш перед поверненням
        return $this->memoizedTotals = $totals;
    }

    public function getTotalKcalAttribute(): float { return round((float)($this->calculated_totals['kcal'] ?? 0), 1); }
    public function getTotalProtAttribute(): float { return round((float)($this->calculated_totals['prot'] ?? 0), 1); }
    public function getTotalFatAttribute(): float  { return round((float)($this->calculated_totals['fat']  ?? 0), 1); }
    public function getTotalCarbAttribute(): float { return round((float)($this->calculated_totals['carb'] ?? 0), 1); }
    public function getTotalCostAttribute(): float { return round((float)($this->calculated_totals['cost'] ?? 0), 2); }
    public function getInputWeightAttribute(): float { return round((float)($this->calculated_totals['input_weight'] ?? 0), 1); }
    public function getOutputWeightAttribute(): float { return round((float)($this->calculated_totals['output_weight'] ?? 0), 1); }
}