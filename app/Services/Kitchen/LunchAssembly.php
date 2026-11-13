<?php

namespace App\Services\Kitchen;

use App\Models\LunchOrder;
use App\Support\SchemaReady;

/**
 * Збирання корпоративних обідів: хто що замовив. Основа для наліпок на бокси
 * й зборочного аркуша по компаніях. Комплект — усі бокси однієї людини.
 *
 * Без цін: документ для кухні.
 */
final class LunchAssembly
{
    public const KINDS = ['main' => 'Основна', 'light' => 'Легка', 'extra' => 'Додаткова'];

    /**
     * @return array<int, array{
     *   company:string, address:?string, window:?string, contact:?string, comment:?string,
     *   sets:int, has_people:bool,
     *   people: array<int, array{name:string, items: array<int, array{name:string, kind:?string, weight_g:?int}>}>,
     *   totals: array<int, array{name:string, kind:?string, weight_g:?int, qty:int}>
     * }>
     */
    public static function forDate(string $date): array
    {
        if (! SchemaReady::has('lunch_orders')) {
            return [];
        }

        return LunchOrder::with('lines')->whereDate('date', $date)->orderBy('company_name')->get()
            ->map(function (LunchOrder $o) {
                $people = collect($o->people ?? [])
                    ->map(fn ($p) => [
                        'name'  => trim((string) ($p['name'] ?? '')) ?: 'Без імені',
                        'items' => self::sortItems(collect($p['lines'] ?? [])->map(fn ($l) => [
                            'name'     => (string) ($l['name'] ?? '—'),
                            'kind'     => $l['kind'] ?? null,
                            'weight_g' => isset($l['weight_g']) ? (int) $l['weight_g'] : null,
                        ])->all()),
                    ])
                    ->filter(fn ($p) => $p['items'] !== [])
                    ->sort(fn ($a, $b) => self::compareNames($a['name'], $b['name']))->values()->all();

                return [
                    'company'    => (string) $o->company_name,
                    'address'    => $o->address,
                    'window'     => $o->delivery_window,
                    'contact'    => $o->contact,
                    'comment'    => $o->comment,
                    'sets'       => (int) $o->sets,
                    'has_people' => $people !== [],
                    'people'     => $people,
                    'totals'     => self::sortItems($o->lines->map(fn ($l) => [
                        'name' => (string) $l->name, 'kind' => $l->kind,
                        'weight_g' => $l->weight_g ? (int) $l->weight_g : null, 'qty' => (int) $l->qty,
                    ])->all()),
                ];
            })->all();
    }

    /**
     * Наліпки на бокси: одна на страву людини, з номером боксу в комплекті.
     * Якщо Lunch Hub не прислав розбивку по людях — наліпки без імені, за кількістю.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function stickers(string $date, bool $withBag = true): array
    {
        $out = [];

        foreach (self::forDate($date) as $c) {
            $base = ['company' => $c['company'], 'window' => $c['window'], 'date' => $date];

            if (! $c['has_people']) {
                foreach ($c['totals'] as $t) {
                    for ($i = 0; $i < $t['qty']; $i++) {
                        $out[] = $base + ['type' => 'box', 'person' => null, 'dish' => $t['name'], 'kind' => $t['kind'],
                            'weight_g' => $t['weight_g'], 'n' => null, 'of' => null];
                    }
                }

                continue;
            }

            foreach ($c['people'] as $p) {
                $of = count($p['items']);

                // Наліпка на пакет: увесь комплект людини одним списком.
                if ($withBag) {
                    $out[] = $base + ['type' => 'bag', 'person' => $p['name'], 'items' => $p['items'], 'of' => $of];
                }
                foreach ($p['items'] as $i => $item) {
                    $out[] = $base + ['type' => 'box', 'person' => $p['name'], 'dish' => $item['name'], 'kind' => $item['kind'],
                        'weight_g' => $item['weight_g'], 'n' => $i + 1, 'of' => $of];
                }
            }
        }

        return $out;
    }

    /** Український алфавіт: без цього «Ірина» стає перед «Андрій» (І має менший код, ніж А). */
    private static function compareNames(string $a, string $b): int
    {
        static $collator = null;
        if ($collator === null && class_exists(\Collator::class)) {
            $collator = new \Collator('uk_UA');
        }

        return $collator ? (int) $collator->compare($a, $b) : strcmp(mb_strtolower($a), mb_strtolower($b));
    }

    /** Основна → легка → додаткова, далі за назвою. */
    private static function sortItems(array $items): array
    {
        $order = array_flip(array_keys(self::KINDS));
        usort($items, fn ($a, $b) => [$order[$a['kind']] ?? 9, $a['name']] <=> [$order[$b['kind']] ?? 9, $b['name']]);

        return $items;
    }
}
