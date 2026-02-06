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
     * Розрахунок усіх показників з урахуванням відсотка виходу (Yield %)
     */
    public function getCalculatedTotalsAttribute(): array
    {
        $totals = [
            'kcal' => 0, 'prot' => 0, 'fat' => 0, 
            'carb' => 0, 'cost' => 0, 'weight' => 0
        ];

        foreach ($this->dishIngredients as $item) {
            $source = ($item->type === 'product') ? $item->ingredient : $item->childDish;

            if ($source) {
                $netWeight = (float)$item->net_weight_g;
                
                if ($item->type === 'product') {
                    // 1. Продукти: розрахунок як і раніше (тут все вірно)
                    $yield = (float)($source->yield_percent ?: 100);
                    $grossWeight = ($netWeight * 100) / $yield;

                    // Тут у вас вже було ділення на 1000, тому продукти рахувались правильно
                    $totals['cost'] += ($source->price_per_kg / 1000 * $grossWeight);

                    $totals['kcal'] += ($source->calories_100g * $netWeight / 100);
                    $totals['prot'] += ($source->proteins_100g * $netWeight / 100);
                    $totals['fat'] += ($source->fats_100g * $netWeight / 100);
                    $totals['carb'] += ($source->carbs_100g * $netWeight / 100);
                } else {
                    // 2. ПФ (Напівфабрикати)
                    $pfTotals = $source->calculated_totals;
                    
                    // === ВИПРАВЛЕННЯ ТУТ ===
                    // Раніше ми брали вагу з бази ($source->base_weight_g).
                    // Якщо там був 0, ми ділили на 1, і ціна множилася на вагу (x1000).
                    // ТЕПЕР: Якщо в базі 0, ми беремо розраховану вагу ($pfTotals['weight']).
                    $pfWeight = (float)$source->base_weight_g ?: $pfTotals['weight'];
                    
                    // Захист: якщо навіть розрахована вага 0, то ставимо 1
                    $pfWeight = $pfWeight ?: 1; 

                    $ratio = $netWeight / $pfWeight;

                    $totals['kcal'] += ($pfTotals['kcal'] * $ratio);
                    $totals['prot'] += ($pfTotals['prot'] * $ratio);
                    $totals['fat'] += ($pfTotals['fat'] * $ratio);
                    $totals['carb'] += ($pfTotals['carb'] * $ratio);
                    $totals['cost'] += ($pfTotals['cost'] * $ratio);
                }
                $totals['weight'] += $netWeight;
            }
        }

        return $totals;
    }

    // Аксесори для виклику в Filament
    public function getTotalKcalAttribute() { return round($this->calculated_totals['kcal'], 1); }
    public function getTotalProtAttribute() { return round($this->calculated_totals['prot'], 1); }
    public function getTotalFatAttribute() { return round($this->calculated_totals['fat'], 1); }
    public function getTotalCarbAttribute() { return round($this->calculated_totals['carb'], 1); }
    public function getTotalCostAttribute() { return round($this->calculated_totals['cost'], 2); }
}