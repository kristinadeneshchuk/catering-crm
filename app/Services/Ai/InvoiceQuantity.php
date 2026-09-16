<?php

namespace App\Services\Ai;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocumentItem;

/**
 * Скільки насправді прийшло в базовій одиниці товару.
 *
 * У накладній пишуть штуками («3 шт × 1560 г»), а на складі позиція може
 * вестись у кілограмах чи навіть грамах. Якщо просто взяти 3, склад отримає
 * 3 кг замість 4,68 — тому фасування рахуємо тут, а не в голові ШІ.
 */
class InvoiceQuantity
{
    /**
     * @param  float|null  $packSize   розмір однієї упаковки з назви рядка (1560)
     * @param  string|null  $packUnit  одиниця цієї упаковки (г)
     * @return array{qty: float, unit: string, note: ?string, warning?: string}
     */
    public function resolve(
        Ingredient|Packaging $item,
        float $quantity,
        string $rowUnit,
        ?float $packSize = null,
        ?string $packUnit = null,
    ): array {
        $base    = StockDocumentItem::canonUnit($item->unit ?? 'шт') ?: 'шт';
        $rowUnit = StockDocumentItem::canonUnit($rowUnit) ?: $base;

        // Одиниця накладної та облікова — з однієї групи: переведе сама модель.
        if ($this->sameGroup($rowUnit, $base)) {
            return ['qty' => $quantity, 'unit' => $rowUnit, 'note' => null];
        }

        $packSize = $packSize !== null && $packSize > 0 ? $packSize : null;
        $packUnit = $packSize ? (StockDocumentItem::canonUnit((string) $packUnit) ?: null) : null;

        // Запасний варіант — фасування з картки товару.
        if (! $packSize && (float) ($item->package_weight ?? 0) > 0) {
            $packSize = (float) $item->package_weight;
            $packUnit = StockDocumentItem::canonUnit((string) ($item->package_unit ?? '')) ?: null;

            // У картці «шт» іноді означає одиницю самого товару, а не фасування.
            if ($packUnit === 'шт' || $packUnit === null) {
                $packUnit = $base === 'шт' ? null : $base;
            }
        }

        // Рідини: у накладній мілілітри, на складі кілограми. На кухні рахують
        // 1 л = 1 кг — інакше кожен соус довелося б зважувати.
        if ($packSize && $packUnit && ! $this->sameGroup($packUnit, $base)
            && $this->litreKilo($packUnit, $base)) {
            [$packSize, $packUnit] = $this->litreKilo($packUnit, $base)($packSize);
            $bridged = true;
        }

        // Рахують штуками, облік — у вазі чи обʼємі: 3 шт × 1560 г = 4,68 кг.
        if ($rowUnit === 'шт' && $packSize && $packUnit && $this->sameGroup($packUnit, $base)) {
            return [
                'qty'  => round($quantity * $packSize, 3),
                'unit' => $packUnit,
                'note' => $this->num($quantity).' шт × '.$this->num($packSize).' '.$packUnit
                    .(($bridged ?? false) ? ' (1 л = 1 кг)' : ''),
            ];
        }

        // Рахують вагою чи обʼємом, облік — штуками: 15 л ÷ 0,9 л = 16,667 шт.
        if ($base === 'шт' && $packSize && $rowUnit !== 'шт') {
            $packInRowUnit = $packUnit && $this->sameGroup($packUnit, $rowUnit)
                ? $packSize * StockDocumentItem::unitFactor($packUnit, $rowUnit)
                : $packSize;

            if ($packInRowUnit > 0) {
                return [
                    'qty'  => round($quantity / $packInRowUnit, 3),
                    'unit' => 'шт',
                    'note' => $this->num($quantity).' '.$rowUnit.' ÷ '.$this->num($packSize).' '.($packUnit ?: $rowUnit).' на упаковку',
                ];
            }
        }

        // Фасування невідоме — лишаємо як є і просимо людину перевірити.
        return [
            'qty'  => $quantity,
            'unit' => $rowUnit,
            'note' => null,
            'warning' => 'у накладній '.$this->num($quantity).' '.$rowUnit.', а облік у «'.$base.'» — вкажіть фасування вручну',
        ];
    }

    /**
     * Переклад обʼєму у вагу й навпаки за щільністю 1. Повертає функцію
     * перерахунку або null, якщо це не той випадок.
     */
    private function litreKilo(string $from, string $base): ?\Closure
    {
        $volume = ['л', 'мл'];
        $mass   = ['кг', 'г'];

        if (in_array($from, $volume, true) && in_array($base, $mass, true)) {
            return fn (float $size) => [$from === 'мл' ? $size : $size * 1000, 'г'];
        }

        if (in_array($from, $mass, true) && in_array($base, $volume, true)) {
            return fn (float $size) => [$from === 'г' ? $size : $size * 1000, 'мл'];
        }

        return null;
    }

    private function sameGroup(string $a, string $b): bool
    {
        return in_array($a, StockDocumentItem::compatibleUnits($b), true);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }
}
