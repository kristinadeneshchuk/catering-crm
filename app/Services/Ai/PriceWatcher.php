<?php

namespace App\Services\Ai;

use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Illuminate\Support\Collection;

/**
 * Що подорожчало в цій накладній проти минулих закупівель.
 *
 * Порівнюємо ціну за базову одиницю з останньою проведеною закупівлею того
 * самого товару. Чернетки в порівняння не беремо — вони ще не факт.
 */
class PriceWatcher
{
    /** Від якої різниці показуємо рядок. */
    private const THRESHOLD = 0.10;

    /**
     * @return array<int, array{name: string, now: float, was: float, delta: float, impact: float, date: ?string}>
     */
    public function compare(StockDocument $document): array
    {
        $rows = [];

        foreach ($document->items()->with('itemable')->get() as $item) {
            $now = (float) $item->price;

            if ($now <= 0 || ! $item->itemable) {
                continue;
            }

            $previous = $this->previousPrice($item, $document->id);

            if ($previous === null || $previous['price'] <= 0) {
                continue;
            }

            $delta = ($now - $previous['price']) / $previous['price'];

            if (abs($delta) < self::THRESHOLD) {
                continue;
            }

            $rows[] = [
                'name'   => (string) $item->itemable->name,
                'now'    => round($now, 2),
                'was'    => round($previous['price'], 2),
                'delta'  => $delta,
                'impact' => round(($now - $previous['price']) * (float) $item->qty, 2),
                'date'   => $previous['date'],
                'unit'   => StockDocumentItem::canonUnit($item->itemable->unit ?? 'шт'),
            ];
        }

        usort($rows, fn ($a, $b) => abs($b['impact']) <=> abs($a['impact']));

        return $rows;
    }

    /** Рядок для повідомлення в Telegram і для коментаря в CRM. */
    public function summary(StockDocument $document, int $limit = 5): ?string
    {
        $rows = $this->compare($document);

        if ($rows === []) {
            return null;
        }

        $lines = collect($rows)->take($limit)->map(function (array $row) {
            $sign  = $row['delta'] > 0 ? '▲' : '▼';
            $pct   = round(abs($row['delta']) * 100);
            $when  = $row['date'] ? ' від '.\Carbon\Carbon::parse($row['date'])->format('d.m') : '';

            return $sign.' '.$row['name'].': '.$this->money($row['now']).'/'.$row['unit']
                .' було '.$this->money($row['was']).$when
                .' ('.($row['delta'] > 0 ? '+' : '−').$pct.'%, '
                .($row['impact'] > 0 ? '+' : '−').$this->money(abs($row['impact'])).' на цю накладну)';
        });

        $total = round(collect($rows)->sum('impact'), 2);
        $tail  = $total > 0
            ? 'Загалом дорожче на '.$this->money($total).', ніж минулого разу.'
            : 'Загалом дешевше на '.$this->money(abs($total)).', ніж минулого разу.';

        if (count($rows) > $limit) {
            $tail = 'Ще '.(count($rows) - $limit).' позицій зі зміною ціни. '.$tail;
        }

        return "💰 <b>Ціни проти минулої закупівлі</b>\n".$lines->implode("\n")."\n".$tail;
    }

    /** @return array{price: float, date: ?string}|null */
    private function previousPrice(StockDocumentItem $item, int $exceptDocumentId): ?array
    {
        $row = StockDocumentItem::query()
            ->select('stock_document_items.price', 'stock_documents.operation_date')
            ->join('stock_documents', 'stock_documents.id', '=', 'stock_document_items.stock_document_id')
            ->where('stock_document_items.itemable_type', $item->itemable_type)
            ->where('stock_document_items.itemable_id', $item->itemable_id)
            ->where('stock_document_items.stock_document_id', '!=', $exceptDocumentId)
            ->where('stock_document_items.price', '>', 0)
            ->where('stock_documents.type', 'receipt')
            ->where('stock_documents.status', '!=', StockDocument::STATUS_DRAFT)
            ->orderByDesc('stock_documents.operation_date')
            ->orderByDesc('stock_document_items.id')
            ->first();

        return $row ? ['price' => (float) $row->price, 'date' => $row->operation_date] : null;
    }

    /** Історія закупівель товару — для команди /ціни. */
    public function history(string $itemableType, int $itemableId, int $limit = 6): Collection
    {
        return StockDocumentItem::query()
            ->select(
                'stock_document_items.price',
                'stock_document_items.qty',
                'stock_documents.operation_date',
                'suppliers.name as supplier',
            )
            ->join('stock_documents', 'stock_documents.id', '=', 'stock_document_items.stock_document_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'stock_documents.supplier_id')
            ->where('stock_document_items.itemable_type', $itemableType)
            ->where('stock_document_items.itemable_id', $itemableId)
            ->where('stock_documents.type', 'receipt')
            ->where('stock_documents.status', '!=', StockDocument::STATUS_DRAFT)
            ->where('stock_document_items.price', '>', 0)
            ->orderByDesc('stock_documents.operation_date')
            ->limit($limit)
            ->get();
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ').' ₴';
    }
}
