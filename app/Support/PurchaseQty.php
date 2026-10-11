<?php

namespace App\Support;

use App\Models\Ingredient;
use App\Models\StockDocumentItem;

/**
 * Кількість у списку закупки так, як її купують: склад веде все в кг/л,
 * а закупнику зрозуміліше «60 г» замість «0.06 кг» і «2 шт по 200 г»
 * для штучного й фасованого.
 */
class PurchaseQty
{
    public static function format(float $value, string $unit): string
    {
        $u = StockDocumentItem::canonUnit($unit);
        if (in_array($u, ['кг', 'л'], true) && $value > 0 && $value < 1) {
            return max(1, (int) ceil(round($value * 1000, 3))).' '.($u === 'кг' ? 'г' : 'мл');
        }

        return number_format($value, $value < 10 ? 2 : ($value < 100 ? 1 : 0), '.', ' ').' '.$unit;
    }

    /** «2 шт по 200 г» / «1 уп. по 1.2 кг» — скільки штук чи упаковок узяти, якщо відома вага однієї. */
    public static function pieces(Ingredient $ing, float $toBuy): ?string
    {
        $w = (float) $ing->package_weight;
        if ($w <= 0 || $toBuy <= 0) {
            return null;
        }
        // Вага штуки в одиниці складу (кг/л); старі картки писали її в г/мл.
        if (in_array(StockDocumentItem::canonUnit((string) $ing->package_unit), ['г', 'мл'], true) || $w > 10) {
            $w /= 1000;
        }

        $n = (int) ceil(round($toBuy / $w, 6));
        $piece = $w < 1 ? round($w * 1000).(StockDocumentItem::canonUnit($ing->unit) === 'л' ? ' мл' : ' г')
            : rtrim(rtrim(number_format($w, 2, '.', ''), '0'), '.').' '.$ing->unit;

        // Від пів кіло — це вже упаковка (коробка круасанів 1,2 кг, олія 5 л), а не штука.
        $label = $w >= 0.5 ? 'уп.' : 'шт';

        return "{$n} {$label} по {$piece}";
    }
}
