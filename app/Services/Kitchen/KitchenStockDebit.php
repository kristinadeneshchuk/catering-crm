<?php

namespace App\Services\Kitchen;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Списання зі складу за нормою: план дня × техкарти (разом з індивідуальними
 * меню) + упаковка. Один день готування → документи write_off (продукти й
 * упаковка окремо, кожен на свій склад), залишки зменшують хуки
 * StockDocumentItem.
 *
 * Кухня готує на завтра; у п'ятницю — на суботу й неділю, у суботу не готує.
 *
 * Що вже списано, тримаємо прапорцями в settings:
 *  - stock_debited_{дата готування} — легасі-кнопка ставила його і списувала
 *    лише наступний день; ми теж ставимо (крім суботи), щоб сторінка бачила
 *    зміну закритою;
 *  - stock_debited_food_{день їжі} — точна позначка по дню їжі. Унікальний
 *    ключ settings.key не дає провести той самий день двічі.
 * День їжі F вважаємо списаним, якщо є його food-прапорець або прапорець
 * дати готування F−1 (так працювала легасі-кнопка, і так само виходить для
 * нової схеми, бо суботній прапорець готування ми не ставимо).
 */
class KitchenStockDebit
{
    public const COOK_FLAG = 'stock_debited_';
    public const FOOD_FLAG = 'stock_debited_food_';

    public function __construct(
        private readonly ProductionPlanBuilder $builder,
        private readonly StockPriceBook $prices,
    ) {}

    /** На які дні їжі кухня готує в цю дату. */
    public static function foodDatesFor(CarbonInterface $cookDate): array
    {
        $d = Carbon::parse($cookDate->format('Y-m-d'));

        return match (true) {
            $d->isFriday()   => [$d->copy()->addDay()->format('Y-m-d'), $d->copy()->addDays(2)->format('Y-m-d')],
            $d->isSaturday() => [],
            default          => [$d->copy()->addDay()->format('Y-m-d')],
        };
    }

    public function isFoodDateCovered(string $foodDate): bool
    {
        $prevCook = Carbon::parse($foodDate)->subDay()->format('Y-m-d');

        return DB::table('settings')
            ->whereIn('key', [self::FOOD_FLAG . $foodDate, self::COOK_FLAG . $prevCook])
            ->where('value', '1')
            ->exists();
    }

    /** Дні їжі цієї дати готування, які ще не списані. */
    public function pendingFoodDates(CarbonInterface $cookDate): array
    {
        return array_values(array_filter(
            self::foodDatesFor($cookDate),
            fn (string $f) => !$this->isFoodDateCovered($f),
        ));
    }

    /**
     * Розрахунок без запису.
     *
     * @return array{
     *   cook_date: string, food_dates: string[],
     *   days: array<string, array{orders: int, missing: array}>,
     *   lines: array<int, array>, skipped: array<int, array>,
     *   ingredients_sum: float, packaging_sum: float, total: float,
     *   blocking: bool,
     * }
     */
    public function plan(CarbonInterface $cookDate, ?array $foodDates = null): array
    {
        $cook = Carbon::parse($cookDate->format('Y-m-d'));
        $foodDates ??= $this->pendingFoodDates($cook);

        $grams = [];
        $packs = [];
        $days = [];

        foreach ($foodDates as $food) {
            $built = $this->builder->build($food);

            $days[$food] = [
                'orders'  => $built['orders']->count(),
                'missing' => array_map(fn ($m) => [
                    'plan'       => $m['plan']->name ?? '—',
                    'day_number' => $m['day_number'],
                    'orders'     => $m['orders_count'],
                ], $built['missing_plans']),
            ];

            foreach ($this->builder->collectIngredientGrams($built['report']) as $id => $g) {
                $grams[$id] = ($grams[$id] ?? 0) + $g;
            }
            foreach ($this->builder->collectPackagingQty($built['orders'], $food) as $id => $q) {
                $packs[$id] = ($packs[$id] ?? 0) + $q;
            }
        }

        $lines = [];
        $skipped = [];

        $ingredients = Ingredient::whereIn('id', array_keys($grams))->get()->keyBy('id');
        foreach ($grams as $id => $g) {
            $ing = $ingredients->get($id);
            if (!$ing || $g <= 0) continue;

            [$qty, $note] = self::gramsToStockQty($ing, $g);
            if ($qty === null) {
                $skipped[] = ['kind' => 'ingredient', 'id' => $id, 'name' => $ing->name, 'unit' => $ing->unit, 'grams' => round($g, 1), 'reason' => $note];
                continue;
            }
            $qty = round($qty, 3);
            if ($qty <= 0) continue;

            [$price, $source] = $this->ingredientPrice($ing, $cook);

            $lines[] = [
                'kind'         => 'ingredient',
                'id'           => $id,
                'name'         => $ing->name,
                'unit'         => $ing->unit,
                'grams'        => round($g, 1),
                'qty'          => $qty,
                'price'        => round($price, 4),
                'price_source' => $source,
                'sum'          => round($qty * $price, 2),
                'note'         => $note,
                'stock_before' => (float) $ing->stock,
            ];
        }

        $packagings = Packaging::whereIn('id', array_keys($packs))->get()->keyBy('id');
        foreach ($packs as $id => $q) {
            $pack = $packagings->get($id);
            if (!$pack || $q <= 0) continue;

            [$price, $source] = $this->packagingPrice($pack, $cook);

            $lines[] = [
                'kind'         => 'packaging',
                'id'           => $id,
                'name'         => $pack->name,
                'unit'         => $pack->unit ?: 'шт',
                'grams'        => null,
                'qty'          => (float) $q,
                'price'        => round($price, 4),
                'price_source' => $source,
                'sum'          => round($q * $price, 2),
                'note'         => null,
                'stock_before' => (float) $pack->stock,
            ];
        }

        $ingSum = array_sum(array_map(fn ($l) => $l['kind'] === 'ingredient' ? $l['sum'] : 0, $lines));
        $packSum = array_sum(array_map(fn ($l) => $l['kind'] === 'packaging' ? $l['sum'] : 0, $lines));

        return [
            'cook_date'       => $cook->format('Y-m-d'),
            'food_dates'      => $foodDates,
            'days'            => $days,
            'lines'           => $lines,
            'skipped'         => $skipped,
            'ingredients_sum' => round($ingSum, 2),
            'packaging_sum'   => round($packSum, 2),
            'total'           => round($ingSum + $packSum, 2),
            // Замовлення є, а меню на цей день циклу немає — норма неповна.
            'blocking'        => collect($days)->contains(fn ($d) => !empty($d['missing'])),
        ];
    }

    /**
     * Проводить розрахунок: прапорці + документи write_off. Усе в одній
     * транзакції; якщо хтось уже списав цей день їжі — унікальний ключ
     * прапорця кине виняток, і нічого не запишеться.
     *
     * @return StockDocument[] створені документи (порожньо, якщо в день не було замовлень)
     */
    public function post(array $plan, ?int $userId = null): array
    {
        if ($plan['blocking']) {
            throw new RuntimeException('Немає меню для частини замовлень — норма неповна, списання не проведено.');
        }
        if (empty($plan['food_dates'])) {
            return [];
        }

        $cook = Carbon::parse($plan['cook_date']);
        $now = now();

        return DB::transaction(function () use ($plan, $cook, $now) {
            foreach ($plan['food_dates'] as $food) {
                DB::table('settings')->insert([
                    'key' => self::FOOD_FLAG . $food, 'value' => '1',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if (!$cook->isSaturday()) {
                DB::table('settings')->insertOrIgnore([
                    'key' => self::COOK_FLAG . $cook->format('Y-m-d'), 'value' => '1',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $foodLabel = implode(', ', array_map(fn ($f) => Carbon::parse($f)->format('d.m'), $plan['food_dates']));
            $docs = [];

            foreach (['ingredient' => Ingredient::class, 'packaging' => Packaging::class] as $kind => $class) {
                $lines = array_filter($plan['lines'], fn ($l) => $l['kind'] === $kind);
                if (empty($lines)) continue;

                $doc = StockDocument::create([
                    'type'           => 'write_off',
                    'warehouse_id'   => self::warehouseFor($class),
                    'operation_date' => $cook->copy()->setTime(23, 59),
                    'status'         => 'completed',
                    'is_paid'        => false,
                    'total_sum'      => 0,
                    'comment'        => ($kind === 'ingredient' ? 'Списання за нормою' : 'Списання упаковки за нормою')
                        . ": їжа на {$foodLabel}",
                ]);

                foreach ($lines as $l) {
                    StockDocumentItem::create([
                        'stock_document_id' => $doc->id,
                        'itemable_type'     => $class,
                        'itemable_id'       => $l['id'],
                        'qty'               => $l['qty'],
                        'price'             => $l['price'],
                        'total_price'       => $l['sum'],
                    ]);
                }

                $docs[] = $doc->fresh();
            }

            return $docs;
        });
    }

    /**
     * Брутто-грами з техкарти → кількість в одиниці складу.
     *
     * @return array{0: ?float, 1: ?string} [кількість або null, примітка/причина пропуску]
     */
    public static function gramsToStockQty(Ingredient $ing, float $grams): array
    {
        $unit = StockDocumentItem::canonUnit($ing->unit);

        return match ($unit) {
            'кг', 'л' => [$grams / 1000, null],
            'г', 'мл' => [$grams, null],
            'шт'      => self::gramsToPieces($ing, $grams),
            default   => [null, "невідома одиниця «{$ing->unit}»"],
        };
    }

    /**
     * «Штучні» позиції з упаковкою насправді ведуться на складі в кг: прихід
     * пише qty = упаковок × вага упаковки (5 уп. ковбасок × 0.75 → 3.75), і
     * ціна в накладних — за кг. Тож брутто-грами переводимо в кг, як для «кг».
     * Без ваги упаковки (або з вагою, записаною не в кг, як «300» у булочки)
     * одиниця невідома — таке не вгадуємо, а показуємо окремо.
     */
    private static function gramsToPieces(Ingredient $ing, float $grams): array
    {
        $w = (float) $ing->package_weight;

        if ($w <= 0) {
            return [null, 'шт без ваги упаковки'];
        }
        if ($w > 10) {
            return [null, "шт: вага упаковки «{$w}» — не кг"];
        }

        return [$grams / 1000, 'шт з упаковкою — склад у кг'];
    }

    /**
     * Ціна за одиницю складу: медіана приходів на дату; якщо приходів немає
     * або медіана відрізняється від ціни картки більш ніж у 5 разів — картка.
     *
     * @return array{0: float, 1: string} [ціна, receipts|card|card_check]
     */
    private function ingredientPrice(Ingredient $ing, CarbonInterface $date): array
    {
        $perKg = (float) $ing->price_per_kg;
        $card = in_array(StockDocumentItem::canonUnit($ing->unit), ['г', 'мл'], true) ? $perKg / 1000 : $perKg;

        return self::pickPrice($this->prices->priceAt(Ingredient::class, $ing->id, $date), $card);
    }

    private function packagingPrice(Packaging $pack, CarbonInterface $date): array
    {
        return self::pickPrice($this->prices->priceAt(Packaging::class, $pack->id, $date), (float) $pack->price);
    }

    /** @return array{0: float, 1: string} */
    private static function pickPrice(?float $receipt, float $card): array
    {
        if ($receipt === null) {
            return [$card, 'card'];
        }
        if ($card > 0 && ($receipt > $card * 5 || $receipt < $card / 5)) {
            return [$card, 'card_check'];
        }

        return [$receipt, 'receipts'];
    }

    /** Склад, на який зазвичай приходять такі позиції. */
    private static function warehouseFor(string $itemableType): int
    {
        $id = DB::table('stock_documents as sd')
            ->join('stock_document_items as sdi', 'sdi.stock_document_id', '=', 'sd.id')
            ->where('sdi.itemable_type', $itemableType)
            ->where('sd.type', 'receipt')
            ->groupBy('sd.warehouse_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('sd.warehouse_id');

        $id ??= DB::table('warehouses')->orderBy('id')->value('id');

        if (!$id) {
            throw new RuntimeException('Немає жодного складу для документа списання.');
        }

        return (int) $id;
    }
}
