<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use App\Models\Ingredient;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Supplier;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

/**
 * Фото накладної з чату кухні → чернетка надходження в CRM.
 *
 * ШІ зчитує постачальника, позиції, кількість і ціни та зіставляє рядки з
 * довідником. Документ створюється **чернеткою**: склад, середня ціна й каса
 * не рухаються, поки адмін не натисне «Провести».
 */
class InvoiceReader
{
    public function __construct(private OpsAi $ai)
    {
    }

    /**
     * @param  array<int, string>  $photoPaths  шляхи у приватному сховищі
     */
    public function fromPhotos(array $photoPaths, array $meta = []): ?StockDocument
    {
        $data = $this->ai->ask(
            purpose: AiRun::PURPOSE_INVOICE,
            system: $this->system(),
            prompt: 'Зчитай накладну з фото. Сьогодні '.now()->format('d.m.Y').'. '
                .'Якщо сторінок кілька — це одна накладна. Поверни JSON за схемою.',
            schema: $this->schema(),
            imagePaths: $photoPaths,
            meta: $meta + ['photos' => count($photoPaths)],
        );

        if ($data === null || empty($data['items'])) {
            return null;
        }

        return $this->makeDraft($data, $photoPaths);
    }

    /** Довідник для зіставлення: те саме, що бачить менеджер у формі документа. */
    private function catalog(): string
    {
        $lines = Ingredient::query()->orderBy('name')->get(['id', 'name', 'unit'])
            ->map(fn (Ingredient $i) => 'ING '.$i->id.' · '.$i->name.' · '.StockDocumentItem::canonUnit($i->unit));

        $lines = $lines->concat(
            Packaging::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Packaging $p) => 'PACK '.$p->id.' · '.$p->name.' · шт'),
        );

        return $lines->implode("\n");
    }

    private function system(): string
    {
        return <<<TXT
        Ти зчитуєш накладні постачальників для кухні служби доставки харчування.

        Правила:
        - Бери лише те, що справді видно на фото. Не вигадуй позицій, цін і сум.
        - Кількість і ціну повертай числами. Кому як десятковий роздільник переводь у крапку.
        - `unit` — одна з: кг, г, л, мл, шт. Якщо в накладній «уп.», «ящик», «пач.» — став шт і напиши це в `note`.
        - `total_price` — сума рядка з ПДВ, як у накладній.
        - Кожен рядок зістав із довідником нижче: `match` = ING <id> або PACK <id>. Якщо впевненого збігу немає — `match` = null, і це нормально: людина допише сама.
        - Зіставляй за суттю назви (філе куряче = філе курки), не за буквальним збігом. Не приписуй різні товари до одного запису.
        - У `issues` пиши те, що людині варто перевірити: нерозбірливо, сума рядків не збігається з підсумком, дубль накладної, незвична ціна.

        Довідник (id · назва · базова одиниця):
        {$this->catalog()}
        TXT;
    }

    private function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'supplier_name' => ['type' => ['string', 'null']],
                'supplier_code' => ['type' => ['string', 'null'], 'description' => 'ЄДРПОУ або ІПН, якщо видно'],
                'number'        => ['type' => ['string', 'null']],
                'date'          => ['type' => ['string', 'null'], 'description' => 'Y-m-d'],
                'total'         => ['type' => ['number', 'null']],
                'items'         => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'raw_name'    => ['type' => 'string'],
                            'match'       => ['type' => ['string', 'null'], 'description' => 'ING <id> | PACK <id> | null'],
                            'quantity'    => ['type' => 'number'],
                            'unit'        => ['type' => 'string'],
                            'total_price' => ['type' => 'number'],
                            'note'        => ['type' => ['string', 'null']],
                        ],
                        'required'             => ['raw_name', 'match', 'quantity', 'unit', 'total_price', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
                'issues'     => ['type' => 'array', 'items' => ['type' => 'string']],
                'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
            ],
            'required'             => ['supplier_name', 'supplier_code', 'number', 'date', 'total', 'items', 'issues', 'confidence'],
            'additionalProperties' => false,
        ];
    }

    private function makeDraft(array $data, array $photoPaths): StockDocument
    {
        return DB::transaction(function () use ($data, $photoPaths) {
            $matched   = [];
            $unmatched = [];

            foreach ($data['items'] as $row) {
                $item = $this->resolve($row['match'] ?? null);

                $item ? $matched[] = [$item, $row] : $unmatched[] = $row;
            }

            $document = StockDocument::create([
                'type'           => 'receipt',
                'status'         => StockDocument::STATUS_DRAFT,
                'source'         => StockDocument::SOURCE_AI,
                'warehouse_id'   => Warehouse::orderBy('id')->value('id'),
                'supplier_id'    => $this->supplierId($data),
                'operation_date' => $this->date($data['date'] ?? null),
                'is_paid'        => false,
                'attachments'    => array_map(fn ($p) => ['path' => $p], $photoPaths),
                'comment'        => trim('Накладна '.($data['number'] ?? '').' '.($data['supplier_name'] ?? '')),
                'ai_comment'     => $this->comment($data, $unmatched),
            ]);

            foreach ($matched as [$item, $row]) {
                $unit = StockDocumentItem::canonUnit($row['unit'] ?? '') ?: StockDocumentItem::canonUnit($item->unit ?? 'шт');

                $document->items()->create([
                    'itemable_type' => $item::class,
                    'itemable_id'   => $item->id,
                    'input_qty'     => round((float) $row['quantity'], 3),
                    'input_unit'    => $unit,
                    'qty'           => round((float) $row['quantity'], 3), // модель перерахує в базову одиницю
                    'total_price'   => round((float) $row['total_price'], 2),
                ]);
            }

            return $document->fresh();
        });
    }

    private function resolve(?string $match): Ingredient|Packaging|null
    {
        if (! $match || ! preg_match('/^(ING|PACK)\s*(\d+)$/i', trim($match), $m)) {
            return null;
        }

        return strtoupper($m[1]) === 'ING'
            ? Ingredient::find((int) $m[2])
            : Packaging::find((int) $m[2]);
    }

    /** Постачальника не заводимо автоматично — лише звʼязуємо з наявним. */
    private function supplierId(array $data): ?int
    {
        $name = trim((string) ($data['supplier_name'] ?? ''));
        $code = trim((string) ($data['supplier_code'] ?? ''));

        if ($code !== '' && ($byCode = Supplier::where('inn', $code)->value('id'))) {
            return (int) $byCode;
        }

        if ($name === '') {
            return null;
        }

        $needle = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $name));

        foreach (Supplier::get(['id', 'name']) as $supplier) {
            $hay = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $supplier->name));

            if ($hay !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay))) {
                return (int) $supplier->id;
            }
        }

        return null;
    }

    private function date(?string $raw): \Carbon\Carbon
    {
        try {
            return $raw ? \Carbon\Carbon::parse($raw) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function comment(array $data, array $unmatched): string
    {
        $parts = [];

        if (! empty($data['supplier_name'])) {
            $parts[] = 'Постачальник: '.$data['supplier_name'].(empty($data['number']) ? '' : ', накладна №'.$data['number']);
        }

        if (! empty($data['total'])) {
            $parts[] = 'Сума в накладній: '.number_format((float) $data['total'], 2, '.', ' ').' ₴';
        }

        if ($unmatched !== []) {
            $parts[] = 'Не зіставлено з довідником ('.count($unmatched).'): '
                .collect($unmatched)->map(fn ($r) => $r['raw_name'].' — '.$r['quantity'].' '.$r['unit'].', '.$r['total_price'].' ₴')->implode('; ')
                .'. Додайте ці рядки вручну перед проведенням.';
        }

        foreach ($data['issues'] ?? [] as $issue) {
            $parts[] = '⚠️ '.$issue;
        }

        if (($data['confidence'] ?? 'high') !== 'high') {
            $parts[] = 'Впевненість ШІ: '.($data['confidence'] === 'low' ? 'низька' : 'середня').' — перевірте уважніше.';
        }

        return implode("\n", $parts);
    }
}
