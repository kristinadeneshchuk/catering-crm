<?php

namespace App\Services\Kitchen;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Ціна одиниці складу на дату для оцінки списання: зважена за кількістю
 * медіана цін з прибуткових накладних, датованих не пізніше цієї дати.
 *
 * Чому медіана, а не середня: у накладних трапляються описки введення —
 * «0,4 шт» замість 400 салатників, «0,63 г» замість 0,63 л оцту. Такий
 * рядок має мізерну кількість і величезну ціну за одиницю: середню він
 * перекошує в рази, а зважену медіану майже не зачіпає.
 *
 * Ціна — за базову одиницю, в якій живе stock (qty у рядках накладних
 * уже нормалізовано до неї).
 */
class StockPriceBook
{
    /** @var array<string, array<int, array{0: string, 1: float, 2: float}>> key => [[date, qty, price], ...] за датою */
    private ?array $rows = null;

    /** @var array<string, ?float> */
    private array $cache = [];

    public function priceAt(string $itemableType, int $id, CarbonInterface $date): ?float
    {
        $key = $itemableType . '#' . $id;
        $day = $date->format('Y-m-d');

        if (array_key_exists("$key@$day", $this->cache)) {
            return $this->cache["$key@$day"];
        }

        $points = [];
        foreach ($this->rows()[$key] ?? [] as [$d, $qty, $price]) {
            if ($d > $day) break;
            $points[] = [$price, $qty];
        }

        return $this->cache["$key@$day"] = self::weightedMedian($points);
    }

    /** @param array<int, array{0: float, 1: float}> $points [ціна, вага] */
    public static function weightedMedian(array $points): ?float
    {
        $points = array_values(array_filter($points, fn ($p) => $p[1] > 0));
        if (!$points) return null;

        usort($points, fn ($a, $b) => $a[0] <=> $b[0]);

        $half = array_sum(array_column($points, 1)) / 2;
        $cum = 0.0;

        foreach ($points as $i => [$price, $w]) {
            $cum += $w;
            if (abs($cum - $half) < 1e-9 && isset($points[$i + 1])) {
                return ($price + $points[$i + 1][0]) / 2;
            }
            if ($cum > $half) {
                return $price;
            }
        }

        return end($points)[0];
    }

    private function rows(): array
    {
        if ($this->rows !== null) return $this->rows;

        $rows = DB::table('stock_document_items as sdi')
            ->join('stock_documents as sd', 'sd.id', '=', 'sdi.stock_document_id')
            ->where('sd.type', 'receipt')
            // Чернетки (накладна з фото, ще не проведена) складу не рухають —
            // і в ціну списання не йдуть.
            ->where('sd.status', '!=', \App\Models\StockDocument::STATUS_DRAFT)
            ->where('sdi.qty', '>', 0)
            ->orderBy('sd.operation_date')
            ->orderBy('sdi.id')
            ->get(['sdi.itemable_type', 'sdi.itemable_id', 'sd.operation_date', 'sdi.qty', 'sdi.price']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->itemable_type . '#' . $r->itemable_id][] = [
                substr((string) $r->operation_date, 0, 10),
                (float) $r->qty,
                (float) $r->price,
            ];
        }

        return $this->rows = $out;
    }
}
