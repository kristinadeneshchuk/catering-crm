<?php

namespace App\Services\Ai;

use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Support\Facades\DB;

/**
 * Запобіжник від плутанини одиниць у приході.
 *
 * Найчастіші помилки накладних не в цінах, а в одиницях: 30 яєць записали
 * як 30 кг, 500 г пармезану — як 0,5 г чи 500 кг. Сума в накладній при цьому
 * правильна, тож ціна за кілограм відхиляється від звичної рівно на вагу
 * штуки або в тисячу разів. Такі випадки виправляємо самі й пишемо, що
 * виправили; решту великих відхилень показуємо людині перед проведенням.
 */
class UnitGuard
{
    /** Відхилення в стільки разів уже вважаємо підозрілим. */
    public const SUSPICIOUS = 3.0;

    /** Наскільки точно відхилення має збігтися з ×1000 чи вагою штуки (±35 %). */
    private const TOLERANCE = 0.35;

    /** За скільки днів беремо закупівлі для звичної ціни. */
    private const WINDOW_DAYS = 120;

    /**
     * Звична ціна за одиницю складу: зважена медіана проведених приходів
     * за останні 120 днів, інакше — ціна картки.
     */
    public function reference(Ingredient $ingredient, ?int $exceptDocumentId = null): ?float
    {
        $rows = DB::table('stock_document_items as i')
            ->join('stock_documents as d', 'd.id', '=', 'i.stock_document_id')
            ->where('i.itemable_type', Ingredient::class)
            ->where('i.itemable_id', $ingredient->id)
            ->where('d.type', 'receipt')
            ->where('d.status', '!=', StockDocument::STATUS_DRAFT)
            ->when($exceptDocumentId, fn ($q) => $q->where('d.id', '!=', $exceptDocumentId))
            ->where('d.created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('i.qty', '>', 0)
            ->where('i.price', '>', 0)
            ->get(['i.price', 'i.qty'])
            ->map(fn ($r) => [(float) $r->price, (float) $r->qty])
            ->all();

        $median = \App\Services\Kitchen\StockPriceBook::weightedMedian($rows);

        if ($median !== null) {
            return $median;
        }

        $card = (float) $ingredient->price_per_kg;
        if ($card <= 0) {
            return null;
        }

        // Картка — за кг/л; склад «грамових» позицій — у грамах.
        return in_array(StockDocumentItem::canonUnit($ingredient->unit), ['г', 'мл'], true) ? $card / 1000 : $card;
    }

    /**
     * Перевірити рядок: кількість уже в одиниці складу, сума з накладної.
     *
     * @return array{qty: float, note: ?string, problem: ?string}
     */
    public function check(Ingredient $ingredient, float $qty, float $total, ?int $exceptDocumentId = null): array
    {
        $ok = ['qty' => $qty, 'note' => null, 'problem' => null];

        if ($qty <= 0 || $total <= 0) {
            return $ok;
        }

        $reference = $this->reference($ingredient, $exceptDocumentId);
        if (! $reference) {
            return $ok;
        }

        $ratio = ($total / $qty) / $reference;
        if ($ratio <= self::SUSPICIOUS && $ratio >= 1 / self::SUSPICIOUS) {
            return $ok;
        }

        $unit = StockDocumentItem::canonUnit($ingredient->unit);
        $piece = $this->pieceWeight($ingredient);

        // Ціна в тисячу разів вища — кількість у тисячу разів замала (0,5 замість 500).
        if ($this->near($ratio, 1000)) {
            return ['qty' => $qty * 1000, 'note' => $this->num($qty).' → '.$this->num($qty * 1000).' '.$unit.' (одиниці переплутали: ×1000)', 'problem' => null];
        }
        if ($this->near($ratio, 1 / 1000)) {
            return ['qty' => $qty / 1000, 'note' => $this->num($qty).' → '.$this->num($qty / 1000).' '.$unit.' (одиниці переплутали: ÷1000)', 'problem' => null];
        }

        // Ціна нижча рівно на вагу штуки — це штуки, записані як кілограми.
        if ($piece && $this->near($ratio, $piece)) {
            return ['qty' => $qty * $piece, 'note' => $this->num($qty).' шт × '.$this->num($piece).' '.$unit.' = '.$this->num($qty * $piece).' '.$unit, 'problem' => null];
        }
        if ($piece && $this->near($ratio, 1 / $piece)) {
            return ['qty' => $qty / $piece, 'note' => $this->num($qty).' '.$unit.' — це кілограми, а не штуки: '.$this->num($qty / $piece).' шт', 'problem' => null];
        }

        return ['qty' => $qty, 'note' => null, 'problem' => sprintf(
            '%s: %s ₴/%s, а зазвичай %s ₴/%s (у %s раз%s) — перевірте кількість і одиницю',
            $ingredient->name,
            $this->money($total / $qty), $unit, $this->money($reference), $unit,
            $this->num($ratio >= 1 ? $ratio : 1 / $ratio), $ratio >= 1 ? 'и дорожче' : 'и дешевше',
        )];
    }

    /**
     * Рядки проведення, ціна яких підозріла. Показуємо у вікні «Провести».
     *
     * @return array<int, string>
     */
    public function problems(StockDocument $document): array
    {
        if ($document->type !== 'receipt') {
            return [];
        }

        $out = [];
        foreach ($document->items()->with('itemable')->get() as $item) {
            if (! $item->itemable instanceof Ingredient) {
                continue;
            }
            $res = $this->check($item->itemable, (float) $item->qty, (float) $item->total_price, $document->id);
            if ($res['problem']) {
                $out[] = $res['problem'];
            } elseif ($res['note']) {
                $out[] = $item->itemable->name.': схоже, '.$res['note'];
            }
        }

        return $out;
    }

    /** Вага однієї штуки чи упаковки в одиниці складу. */
    private function pieceWeight(Ingredient $ingredient): ?float
    {
        $weight = (float) $ingredient->package_weight;
        if ($weight <= 0) {
            return null;
        }

        $packUnit = StockDocumentItem::canonUnit((string) $ingredient->package_unit);
        $factor = in_array($packUnit, StockDocumentItem::compatibleUnits($ingredient->unit), true)
            ? StockDocumentItem::unitFactor($packUnit, $ingredient->unit)
            : 1.0;

        return $weight * $factor;
    }

    private function near(float $ratio, float $target): bool
    {
        return $target > 0 && abs($ratio / $target - 1) <= self::TOLERANCE;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 3, ',', ' '), '0'), ',');
    }

    private function money(float $v): string
    {
        return number_format($v, $v < 10 ? 2 : 0, ',', ' ');
    }
}
