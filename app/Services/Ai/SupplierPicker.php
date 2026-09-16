<?php

namespace App\Services\Ai;

use App\Models\StockDocument;
use App\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * Хто постачальник цієї накладної.
 *
 * У бланках, які нам шлють, поле постачальника часто порожнє, тож ШІ не має
 * що зчитувати. Замість запитання текстом даємо кнопки з тими, у кого справді
 * купуємо — натиснув і поїхали далі.
 */
class SupplierPicker
{
    public const CALLBACK = 'sup';

    /** Скільки кнопок показувати. Більше — і повідомлення стає сходами. */
    private const LIMIT = 6;

    /** @return array<int, Supplier> */
    public function suggest(): array
    {
        $recent = DB::table('stock_documents')
            ->select('supplier_id', DB::raw('COUNT(*) as n'))
            ->whereNotNull('supplier_id')
            ->where('type', 'receipt')
            ->where('operation_date', '>=', now()->subMonths(6))
            ->groupBy('supplier_id')
            ->orderByDesc('n')
            ->limit(self::LIMIT)
            ->pluck('supplier_id')
            ->all();

        $suppliers = Supplier::whereIn('id', $recent)->get()->keyBy('id');
        $ordered   = collect($recent)->map(fn ($id) => $suppliers[$id] ?? null)->filter();

        if ($ordered->count() < self::LIMIT) {
            $ordered = $ordered->concat(
                Supplier::whereNotIn('id', $ordered->pluck('id'))
                    ->orderBy('name')->limit(self::LIMIT - $ordered->count())->get(),
            );
        }

        return $ordered->values()->all();
    }

    /** Клавіатура під повідомленням про чернетку. */
    public function keyboard(StockDocument $document): array
    {
        $rows = [];

        foreach (array_chunk($this->suggest(), 2) as $pair) {
            $rows[] = array_map(fn (Supplier $s) => [
                'text'          => mb_substr($s->name, 0, 28),
                'callback_data' => self::CALLBACK.":{$document->id}:{$s->id}",
            ], $pair);
        }

        $rows[] = [[
            'text' => '➕ Інший — у CRM',
            'url'  => \App\Filament\Resources\StockDocumentResource::getUrl('edit', ['record' => $document]),
        ]];

        return $rows;
    }

    /** @return array{ok: bool, message: string} */
    public function apply(int $documentId, int $supplierId): array
    {
        $document = StockDocument::find($documentId);
        $supplier = Supplier::find($supplierId);

        if (! $document || ! $supplier) {
            return ['ok' => false, 'message' => 'Чернетку або постачальника не знайдено.'];
        }

        if (! $document->isDraft()) {
            return ['ok' => false, 'message' => 'Документ уже проведено — змініть постачальника в CRM.'];
        }

        $document->update(['supplier_id' => $supplier->id]);

        return ['ok' => true, 'message' => 'Постачальник: '.$supplier->name];
    }
}
