<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Ingredient extends Model
{
    public const UNITS = [
        'g' => 'г',
        'kg' => 'кг',
        'ml' => 'мл',
        'l' => 'л',
        'pcs' => 'шт',
    ];

    protected $fillable = [
        'name', 'unit', 'price_per_kg', 'yield_percent',
        'calories_100g', 'proteins_100g', 'fats_100g', 'carbs_100g',
        'stock', 'group', 'photo',
        'is_packaged', 'package_weight', 'package_unit',
    ];

    protected $casts = [
        'stock' => 'decimal:3',
        'price_per_kg' => 'decimal:2',
        'yield_percent' => 'integer',
        'calories_100g' => 'integer',
        'proteins_100g' => 'float',
        'fats_100g' => 'float',
        'carbs_100g' => 'float',
        'is_packaged' => 'boolean',
        'package_weight' => 'decimal:3',
    ];

    /**
     * Скільки БАЗОВИХ одиниць (kg/l/pcs) важить одна упаковка.
     * Напр. упаковка сметани 400 г при базовій «кг» → 0.4.
     * Переводимо через ту саму таблицю конвертації, що й приход.
     */
    public function packageBaseWeight(): float
    {
        if (!$this->is_packaged || !$this->package_weight) {
            return 0.0;
        }

        $factor = StockDocumentItem::unitFactor($this->package_unit ?: $this->unit, $this->unit);

        return (float) $this->package_weight * $factor;
    }

    // Per-process кеш середніх цін (заповнюється preloadAveragePrices або
    // ліниво в getAveragePriceAttribute). Знімає N+1 в аналітиці, друці
    // тех-карт, дашборді складу — там, де ціну читають для багатьох
    // інгредієнтів за один запит.
    protected static array $avgPriceCache = [];

    public function getAveragePriceAttribute(): float
    {
        if (array_key_exists($this->id, self::$avgPriceCache)) {
            return self::$avgPriceCache[$this->id];
        }

        $avgData = \App\Models\StockDocumentItem::query()
            ->where('itemable_id', $this->id)
            ->where('itemable_type', self::class)
            ->whereHas('stockDocument', fn($q) => $q->where('type', 'receipt'))
            ->selectRaw('SUM(qty * price) as total_cost, SUM(qty) as total_qty')
            ->first();

        $price = ($avgData && $avgData->total_qty > 0)
            ? (float) ($avgData->total_cost / $avgData->total_qty)
            : (float) $this->price_per_kg;

        return self::$avgPriceCache[$this->id] = $price;
    }

    /**
     * Завантажує середні ціни всіх інгредієнтів одним SQL і кладе в кеш.
     * Викликати на початку важких запитів (аналітика, друк) — далі
     * \$ingredient->average_price бере з пам'яті, без SQL.
     */
    public static function preloadAveragePrices(): void
    {
        // Стартова заливка: для кожного інгредієнта — fallback на price_per_kg
        foreach (self::query()->get(['id', 'price_per_kg']) as $ing) {
            self::$avgPriceCache[$ing->id] = (float) $ing->price_per_kg;
        }

        // Зважена середня з прибуткових накладних — одним SQL.
        $rows = \Illuminate\Support\Facades\DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.type', 'receipt')
            ->where('sdi.itemable_type', self::class)
            ->selectRaw('sdi.itemable_id as id, SUM(sdi.qty * sdi.price) as total_cost, SUM(sdi.qty) as total_qty')
            ->groupBy('sdi.itemable_id')
            ->get();

        foreach ($rows as $r) {
            if ((float) $r->total_qty > 0) {
                self::$avgPriceCache[$r->id] = (float) $r->total_cost / (float) $r->total_qty;
            }
        }
    }

    public static function clearAveragePriceCache(): void
    {
        self::$avgPriceCache = [];
    }

    public function getTotalSpentAttribute(): float
    {
        return (float) \App\Models\StockDocumentItem::query()
            ->whereIn('itemable_type', [self::class, 'App\Models\Ingredient'])
            ->where('itemable_id', $this->id)
            ->whereHas('stockDocument', fn($q) => $q->where('type', 'receipt'))
            ->sum(\Illuminate\Support\Facades\DB::raw('qty * price'));
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class, 'allergen_ingredient');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(
            Dish::class,
            'dish_ingredients',
            'ingredient_id',
            'dish_id'
        )->withPivot('net_weight_g');
    }
}
