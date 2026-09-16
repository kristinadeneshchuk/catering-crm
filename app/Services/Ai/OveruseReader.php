<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Голосове з кухні про перевитрату → чернетка списання поза нормою.
 *
 * «Взяли ще два кіло курки на індивідуальні» стає документом зі статусом
 * «Чернетка»: склад не рухається, поки адмін не проведе. Причину беремо з
 * фіксованого списку, щоб у звітах було видно, куди йде перевитрата.
 */
class OveruseReader
{
    /** Причини списання поза нормою. */
    public const REASONS = [
        'individual'    => 'індивідуальні меню',
        'spoiled'       => 'зіпсувалось',
        'supplier_fault' => 'брак постачальника',
        'remake'        => 'переробка',
        'tasting'       => 'дегустація',
        'other'         => 'інше',
    ];

    public function __construct(private OpsAi $ai, private InvoiceQuantity $quantity)
    {
    }

    public function fromTranscript(string $transcript, array $meta = []): ?StockDocument
    {
        $data = $this->ai->ask(
            purpose: AiRun::PURPOSE_OVERUSE,
            system: $this->system(),
            prompt: "Кухар сказав: «{$transcript}». Поверни JSON за схемою.",
            schema: $this->schema(),
            meta: $meta + ['chars' => mb_strlen($transcript)],
        );

        if ($data === null || empty($data['items'])) {
            return null;
        }

        return $this->makeDraft($data, $transcript);
    }

    private function system(): string
    {
        $catalog = Ingredient::query()->orderBy('name')->get(['id', 'name', 'unit'])
            ->map(fn (Ingredient $i) => 'ING '.$i->id.' · '.$i->name.' · '.StockDocumentItem::canonUnit($i->unit))
            ->implode("\n");

        $reasons = collect(self::REASONS)->map(fn ($label, $key) => $key.' — '.$label)->implode('; ');

        return <<<TXT
        Ти записуєш за кухарем, що взяли понад норму або зіпсувалось.

        - Розпізнавання мови може плутати слова: «філе» ↔ «філя», «кіло» ↔ «кило». Обирай найближчий товар з довідника.
        - Якщо не впевнений, про який товар ідеться, не вгадуй: пропусти рядок і напиши про це в `unclear`.
        - `quantity` — число, `unit` — кг, г, л, мл або шт.
        - `reason` — одне з: {$reasons}. Не сказали причину — став other.
        - У `note` став дослівну частину фрази про цей товар.

        Довідник (id · назва · базова одиниця):
        {$catalog}
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
                            'match'     => ['type' => 'string', 'description' => 'ING <id>'],
                            'quantity'  => ['type' => 'number'],
                            'unit'      => ['type' => 'string'],
                            'pack_size' => ['type' => ['number', 'null']],
                            'pack_unit' => ['type' => ['string', 'null']],
                            'reason'    => ['type' => 'string'],
                            'note'      => ['type' => ['string', 'null']],
                        ],
                        'required'             => ['match', 'quantity', 'unit', 'pack_size', 'pack_unit', 'reason', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
                'unclear'    => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required'             => ['items', 'unclear', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    private function makeDraft(array $data, string $transcript): ?StockDocument
    {
        return DB::transaction(function () use ($data, $transcript) {
            $lines = [];

            foreach ($data['items'] as $row) {
                if (! preg_match('/^ING\s*(\d+)$/i', trim((string) $row['match']), $m)) {
                    continue;
                }

                $ingredient = Ingredient::find((int) $m[1]);

                if (! $ingredient) {
                    continue;
                }

                $lines[] = [$ingredient, $row, $this->quantity->resolve(
                    $ingredient,
                    (float) $row['quantity'],
                    (string) $row['unit'],
                    isset($row['pack_size']) ? (float) $row['pack_size'] : null,
                    $row['pack_unit'] ?? null,
                )];
            }

            if ($lines === []) {
                return null;
            }

            $document = StockDocument::create([
                'type'           => 'write_off',
                'status'         => StockDocument::STATUS_DRAFT,
                'source'         => StockDocument::SOURCE_AI,
                'warehouse_id'   => Warehouse::orderBy('id')->value('id'),
                'operation_date' => now(),
                'comment'        => 'Поза нормою (голосове з кухні)',
                'ai_comment'     => $this->comment($data, $lines, $transcript),
            ]);

            foreach ($lines as [$ingredient, $row, $resolved]) {
                $document->items()->create([
                    'itemable_type' => Ingredient::class,
                    'itemable_id'   => $ingredient->id,
                    'input_qty'     => $resolved['qty'],
                    'input_unit'    => $resolved['unit'],
                    'qty'           => $resolved['qty'],
                    // Ціна — середня закупівельна: щоб перевитрата була видна в грошах.
                    'total_price'   => round($resolved['qty'] * (float) $ingredient->average_price
                        * StockDocumentItem::unitFactor($resolved['unit'], $ingredient->unit), 2),
                ]);
            }

            return $document->fresh();
        });
    }

    private function comment(array $data, array $lines, string $transcript): string
    {
        $parts = ['🎙 «'.$transcript.'»'];

        foreach ($lines as [$ingredient, $row, $resolved]) {
            $reason = self::REASONS[$row['reason'] ?? 'other'] ?? 'інше';
            $parts[] = '• '.$ingredient->name.' — '.$resolved['qty'].' '.$resolved['unit'].', причина: '.$reason
                .(isset($resolved['warning']) ? ' ⚠️ '.$resolved['warning'] : '');
        }

        if (! empty($data['unclear'])) {
            $parts[] = '⚠️ Не зрозуміло: '.$data['unclear'];
        }

        if (($data['confidence'] ?? 'high') !== 'high') {
            $parts[] = 'Впевненість ШІ: '.($data['confidence'] === 'low' ? 'низька' : 'середня').' — перевірте.';
        }

        return implode("\n", $parts);
    }
}
