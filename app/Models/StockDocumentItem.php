<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockDocumentItem extends Model
{
    protected $guarded = [];

    // Скільки БАЗОВИХ грам/мл/шт в одній одиниці виміру. Використовуємо для
    // конвертації введеної одиниці (напр. «кг») у базову одиницю інгредієнта
    // (напр. «г»), в якій живуть stock і average_price. Ключі — і укр. мітки,
    // і латинські коди, бо в БД трапляються обидва.
    public const UNIT_BASE = [
        'г' => 1,   'g' => 1,
        'кг' => 1000, 'kg' => 1000,
        'мл' => 1,  'ml' => 1,
        'л' => 1000, 'l' => 1000,
        'шт' => 1,  'pcs' => 1,
    ];

    // Групи сумісності — конвертувати можна лише всередині групи (маса↔маса,
    // об'єм↔об'єм). Порядок = порядок опцій у селекті (спочатку більша одиниця).
    public const UNIT_GROUPS = [
        'mass'   => ['кг', 'г'],
        'volume' => ['л', 'мл'],
        'count'  => ['шт'],
    ];

    /** Нормалізує мітку одиниці (латиниця/регістр) до укр. форми зі списку. */
    public static function canonUnit(?string $unit): string
    {
        $u = mb_strtolower(trim((string) $unit));
        return match ($u) {
            'g'   => 'г',
            'kg'  => 'кг',
            'ml'  => 'мл',
            'l'   => 'л',
            'pcs' => 'шт',
            default => $u,
        };
    }

    /** Одиниці, у які можна вводити товар з базовою одиницею $baseUnit. */
    public static function compatibleUnits(?string $baseUnit): array
    {
        $base = self::canonUnit($baseUnit);
        foreach (self::UNIT_GROUPS as $group) {
            if (in_array($base, $group, true)) {
                return $group;
            }
        }
        return [$base ?: 'шт'];
    }

    /**
     * Множник переводу з введеної одиниці у базову:
     *   qty_base = qty_input * factor.
     * Різні групи (маса vs об'єм) → множник 1 (без конвертації).
     */
    public static function unitFactor(?string $inputUnit, ?string $baseUnit): float
    {
        $in   = self::canonUnit($inputUnit);
        $base = self::canonUnit($baseUnit);

        if (!in_array($in, self::compatibleUnits($base), true)) {
            return 1.0;
        }

        $inG   = self::UNIT_BASE[$in]   ?? 1;
        $baseG = self::UNIT_BASE[$base] ?? 1;

        return $baseG > 0 ? $inG / $baseG : 1.0;
    }

    public function stockDocument()
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    public function itemable()
    {
        return $this->morphTo();
    }

    protected static function booted()
    {
        // Нормалізуємо введене «10 кг» у базову одиницю інгредієнта («г»), в
        // якій рахуються склад і середня ціна. Гарантія на рівні БД — не
        // залежимо від того, чи спрацював live-перерахунок у формі.
        static::saving(function ($item) {
            // input_qty/input_unit заповнюються формою (нова закупівля).
            // Якщо їх немає (легасі-рядок, прямий код) — лишаємо qty як є.
            if ($item->input_qty !== null) {
                $itemable = $item->itemable;
                $factor = ($itemable && $item->input_unit)
                    ? self::unitFactor($item->input_unit, $itemable->unit)
                    : 1.0;
                $item->qty = round((float) $item->input_qty * $factor, 3);
            }

            $item->total_price = round((float) $item->total_price, 2);
            $item->price = ((float) $item->qty) != 0.0
                ? round((float) $item->total_price / (float) $item->qty, 4)
                : (float) $item->price;
        });

        static::created(function ($item) {
            $item->applyStock();
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
                $item->stockDocument->syncTransaction();
            }
        });

        static::updating(function ($item) {
            $item->revertStock();
        });

        static::updated(function ($item) {
            $item->applyStock();
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
                $item->stockDocument->syncTransaction();
            }
        });

        static::deleted(function ($item) {
            $item->revertStock();
            if ($item->stockDocument) {
                $item->stockDocument->updateTotalSum();
                $item->stockDocument->syncTransaction();
            }
        });
    }

    public function applyStock()
    {
        if (!$this->itemable || !$this->stockDocument) return;

        $qty = (float) $this->qty;
        $type = $this->stockDocument->type;

        if ($type === 'receipt') {
            // Тільки оновлюємо залишок, не чіпаючи базову ціну в інгредієнті
            $this->itemable->increment('stock', $qty);
        } elseif ($type === 'write_off') {
            $this->itemable->decrement('stock', $qty);
        }
    }

    public function revertStock()
    {
        $originalType = $this->getOriginal('itemable_type') ?: $this->itemable_type;
        $originalId = $this->getOriginal('itemable_id') ?: $this->itemable_id;
        $originalQty = (float) ($this->getOriginal('qty') ?: $this->qty);

        if (!$originalType || !$originalId || !$this->stockDocument) return;

        $itemable = $originalType::find($originalId);
        if (!$itemable) return;

        $type = $this->stockDocument->type;

        if ($type === 'receipt') {
            $itemable->decrement('stock', $originalQty);
        } elseif ($type === 'write_off') {
            $itemable->increment('stock', $originalQty);
        }
    }
}