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
     * Розрахунок усіх показників.
     * ВИПРАВЛЕНО: КБЖУ тепер синхронізовано. Калорії розраховуються суворо з БЖУ (4-9-4).
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
                // Беремо вагу нетто з техкарти
                $weight = (float)$item->net_weight_g;
                
                if ($item->type === 'product') {
                    // === 1. ПРОДУКТИ ===
                    
                    // Ціну рахуємо від Брутто
                    $yield = (float)($source->yield_percent ?: 100);
                    $grossWeight = ($weight * 100) / $yield;
                    $totals['cost'] += ($source->price_per_kg / 1000 * $grossWeight);

                    // Сумуємо ТІЛЬКИ Б/Ж/В (Калорії з бази ігноруємо!)
                    $totals['prot'] += ($source->proteins_100g * $weight / 100);
                    $totals['fat'] += ($source->fats_100g * $weight / 100);
                    $totals['carb'] += ($source->carbs_100g * $weight / 100);
                    
                    // ❌ $totals['kcal'] += ... (Цей рядок ми видалили, щоб не брати "брудні" калорії)

                } else {
                    // === 2. НАПІВФАБРИКАТИ (НФ) ===
                    $pfTotals = $source->calculated_totals;
                    
                    // Коефіцієнт масштабування НФ
                    $pfWeight = (float)$source->base_weight_g ?: $pfTotals['weight'];
                    $pfWeight = $pfWeight ?: 1; 
                    $ratio = $weight / $pfWeight;

                    $totals['cost'] += ($pfTotals['cost'] * $ratio);

                    // Сумуємо ТІЛЬКИ Б/Ж/В з НФ
                    $totals['prot'] += ($pfTotals['prot'] * $ratio);
                    $totals['fat'] += ($pfTotals['fat'] * $ratio);
                    $totals['carb'] += ($pfTotals['carb'] * $ratio);
                    
                    // ❌ Тут теж не додаємо калорії вручну
                }
                
                $totals['weight'] += $weight;
            }
        }

        // 🔥 ФІНАЛЬНИЙ ЕТАП: РАХУЄМО КАЛОРІЇ МАТЕМАТИЧНО
        // Формула: (Білки * 4) + (Жири * 9) + (Вуглеводи * 4)
        $totals['kcal'] = ($totals['prot'] * 4) + ($totals['fat'] * 9) + ($totals['carb'] * 4);

        return $totals;
    }

    // Аксесори для виклику в Filament та Контролерах
    public function getTotalKcalAttribute() { return round($this->calculated_totals['kcal'], 1); }
    public function getTotalProtAttribute() { return round($this->calculated_totals['prot'], 1); }
    public function getTotalFatAttribute() { return round($this->calculated_totals['fat'], 1); }
    public function getTotalCarbAttribute() { return round($this->calculated_totals['carb'], 1); }
    public function getTotalCostAttribute() { return round($this->calculated_totals['cost'], 2); }
}