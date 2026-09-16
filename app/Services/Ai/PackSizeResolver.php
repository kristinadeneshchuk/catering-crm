<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;

/**
 * «Скільки важить одна штука» — питаємо раз, памʼятаємо назавжди.
 *
 * У накладній рахують штуками (7 лавашів), а на складі позиція ведеться в
 * кілограмах. Вагу однієї штуки ніде не написано, тому бот питає її в чаті,
 * зберігає у картці товару (`package_weight`) і перераховує рядки чернетки.
 * Наступного разу питати вже не треба.
 */
class PackSizeResolver
{
    public function __construct(private OpsAi $ai, private InvoiceQuantity $quantity)
    {
    }

    /** Текст питання для чату. */
    public function question(StockDocument $document): ?string
    {
        $pending = $document->ai_state['pending'] ?? [];

        if ($pending === []) {
            return null;
        }

        $lines = collect($pending)->map(
            fn (array $row) => '• '.$row['name'].' — '.$this->num($row['quantity']).' '.$row['unit']
                .', облік у «'.$row['base_unit'].'»',
        )->implode("\n");

        return "⚖️ <b>Скільки важить одна штука?</b>\n{$lines}\n\n"
            ."Відповідайте реплаєм на це повідомлення, наприклад: «лаваш 0,5 кг, крем сир 1 кг».\n"
            .'Запишу у картки товарів і більше не питатиму.';
    }

    /**
     * Розібрати відповідь людини, зберегти фасування й перерахувати рядки.
     *
     * @return array{applied: int, text: string}
     */
    public function apply(StockDocument $document, string $reply): array
    {
        $pending = $document->ai_state['pending'] ?? [];

        if ($pending === []) {
            return ['applied' => 0, 'text' => 'Ця чернетка вже не чекає фасування.'];
        }

        $data = $this->ai->ask(
            purpose: AiRun::PURPOSE_INVOICE,
            system: $this->system($pending),
            prompt: "Відповідь людини: «{$reply}». Зістав її з переліком і поверни JSON.",
            schema: $this->schema(),
            subject: $document,
            meta: ['step' => 'pack_size'],
        );

        if ($data === null || empty($data['items'])) {
            return ['applied' => 0, 'text' => 'Не зрозумів вагу. Напишіть, наприклад: «лаваш 0,5 кг».'];
        }

        $byId    = collect($pending)->keyBy('item_id');
        $applied = [];
        $left    = collect($pending);

        foreach ($data['items'] as $row) {
            $row_id = (int) ($row['item_id'] ?? 0);
            $size   = (float) ($row['pack_size'] ?? 0);
            $unit   = StockDocumentItem::canonUnit((string) ($row['pack_unit'] ?? ''));

            if (! $byId->has($row_id) || $size <= 0 || $unit === '') {
                continue;
            }

            $pendingRow = $byId[$row_id];
            $item       = $pendingRow['item_type'] === Packaging::class
                ? Packaging::find($row_id)
                : Ingredient::find($row_id);

            if (! $item) {
                continue;
            }

            // Памʼять на майбутнє: наступні накладні перерахуються самі.
            if ($item instanceof Ingredient) {
                $item->update(['package_weight' => $size, 'package_unit' => $unit, 'is_packaged' => true]);
            }

            $this->recalculate($document, $item, $pendingRow, $size, $unit);

            $applied[] = $item->name.' — '.$this->num($size).' '.$unit;
            $left      = $left->reject(fn (array $r) => (int) $r['item_id'] === $row_id);
        }

        $document->update([
            'ai_state' => $left->isEmpty()
                ? ['pending' => []] + array_intersect_key($document->ai_state ?? [], ['ask' => true])
                : ['pending' => $left->values()->all()] + array_intersect_key($document->ai_state ?? [], ['ask' => true]),
        ]);

        if ($applied === []) {
            return ['applied' => 0, 'text' => 'Не зрозумів, до якої позиції це стосується. Напишіть назву й вагу.'];
        }

        $text = "✅ Записав фасування:\n• ".implode("\n• ", $applied);

        if ($left->isNotEmpty()) {
            $text .= "\n\nЩе чекають: ".$left->pluck('name')->implode(', ');
        }

        return ['applied' => count($applied), 'text' => $text];
    }

    /** Перерахувати рядок чернетки з новим фасуванням. */
    private function recalculate(StockDocument $document, Ingredient|Packaging $item, array $pendingRow, float $size, string $unit): void
    {
        $line = $document->items()
            ->where('itemable_type', $item::class)
            ->where('itemable_id', $item->id)
            ->first();

        if (! $line) {
            return;
        }

        $resolved = $this->quantity->resolve(
            $item->fresh(),
            (float) $pendingRow['quantity'],
            (string) $pendingRow['unit'],
            $size,
            $unit,
        );

        $line->update([
            'input_qty'  => $resolved['qty'],
            'input_unit' => $resolved['unit'],
            'qty'        => $resolved['qty'],
            'pack_count' => (float) $pendingRow['quantity'],
        ]);
    }

    private function system(array $pending): string
    {
        $list = collect($pending)->map(
            fn (array $row) => $row['item_id'].' · '.$row['name'].' · облік у «'.$row['base_unit'].'»',
        )->implode("\n");

        return <<<TXT
        Людина називає вагу або обʼєм ОДНІЄЇ штуки товару. Зістав сказане з переліком нижче.

        - `item_id` — з переліку. Якщо не зрозуміло, про що йдеться, рядок пропусти.
        - `pack_size` — число, `pack_unit` — кг, г, л або мл. «Пів кіло» = 0.5 кг, «500» без одиниці для товару з обліком у кг = 0.5 кг.
        - Нічого не вигадуй: у відповіді лише те, що людина справді назвала.

        Перелік:
        {$list}
        TXT;
    }

    private function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'items' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'item_id'   => ['type' => 'integer'],
                            'pack_size' => ['type' => 'number'],
                            'pack_unit' => ['type' => 'string'],
                        ],
                        'required'             => ['item_id', 'pack_size', 'pack_unit'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required'             => ['items'],
            'additionalProperties' => false,
        ];
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, ',', ' '), '0'), ',');
    }
}
