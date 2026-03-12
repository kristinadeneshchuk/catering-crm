<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB; // 🔥 Обов'язково додаємо фасад DB для транзакцій

class Inventory extends Model
{
    protected $fillable = [
        'operation_date',
        'type',
        'selected_groups',
        'status',
        'total_surplus',
        'total_shortage',
        'comment',
    ];

    protected $casts = [
        'operation_date' => 'datetime',
        'selected_groups' => 'array', // Автоматично перетворює JSON з бази у звичайний PHP-масив
    ];

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    // 🔥 1. Метод для перерахунку фінансів (Надлишок/Нестача)
    public function recalculateTotals(): void
    {
        $surplus = 0;
        $shortage = 0;

        foreach ($this->items as $item) {
            if ($item->actual_qty === null) continue; // Якщо ще не ввели факт - пропускаємо

            $diff = $item->actual_qty - $item->expected_qty;
            $moneyDiff = $diff * $item->price;

            if ($moneyDiff > 0) {
                $surplus += $moneyDiff;
            } elseif ($moneyDiff < 0) {
                $shortage += abs($moneyDiff);
            }
        }

        $this->update([
            'total_surplus' => $surplus,
            'total_shortage' => $shortage,
        ]);
    }

    // 🔥 2. Метод для фінального ПРОВЕДЕННЯ інвентаризації
    public function applyInventory(): void
    {
        if ($this->status === 'completed') return;

        DB::transaction(function () {
            foreach ($this->items as $item) {
                if ($item->actual_qty === null) continue; // Якщо залишили порожнім - не чіпаємо

                $model = $item->itemable;
                if ($model) {
                    $model->stock = $item->actual_qty; // Жорстко перезаписуємо залишок фактом!
                    $model->save();
                }
            }
            
            // Змінюємо статус на проведений
            $this->update(['status' => 'completed']);
        });
    }
}