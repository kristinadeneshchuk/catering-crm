<?php

namespace App\Console\Commands;

use App\Models\Packaging;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Нагадування власнику в Telegram: упаковки (етикетки, пакети, бокси, кришки,
 * прибори…) лишилось менше ніж THRESHOLD від останньої поставки — час замовляти.
 *
 * Перевіряємо лише те, що списує автосписання (є packaging_type). На кожну
 * поставку — одне нагадування: у settings тримаємо id накладної, про яку вже
 * написали; нова накладна скидає лічильник.
 */
class StockLowPackaging extends Command
{
    protected $signature = 'stock:low-packaging {--dry : Показати повідомлення в консолі, нічого не відправляючи}';
    protected $description = 'Нагадати власнику замовити упаковку, коли лишилось < 30 % від останньої поставки';

    public const THRESHOLD = 0.30;
    private const FLAG = 'low_stock_alert_pkg_';

    public function handle(TelegramService $telegram): int
    {
        $lines = [];

        foreach (Packaging::whereNotNull('packaging_type')->orderBy('packaging_type')->orderBy('name')->get() as $p) {
            $last = DB::table('stock_document_items as i')
                ->join('stock_documents as d', 'd.id', '=', 'i.stock_document_id')
                ->leftJoin('suppliers as s', 's.id', '=', 'd.supplier_id')
                ->where('i.itemable_type', Packaging::class)->where('i.itemable_id', $p->id)
                ->where('d.type', 'receipt')->where('d.status', '!=', 'draft')
                ->orderByDesc('d.operation_date')->orderByDesc('d.id')
                ->first(['d.id as doc_id', 'd.operation_date', 's.name as supplier']);
            if (!$last) continue;

            $batch = (float) DB::table('stock_document_items')
                ->where('stock_document_id', $last->doc_id)
                ->where('itemable_type', Packaging::class)->where('itemable_id', $p->id)
                ->sum('qty');
            if ($batch <= 0) continue;

            $stock = (float) $p->stock;
            if ($stock >= $batch * self::THRESHOLD) continue;

            $flag = self::FLAG . $p->id;
            if ((string) DB::table('settings')->where('key', $flag)->value('value') === (string) $last->doc_id) continue;

            // Середня витрата за 7 днів — щоб сказати, на скільки днів ще вистачить.
            $used7 = (float) DB::table('stock_document_items as i')
                ->join('stock_documents as d', 'd.id', '=', 'i.stock_document_id')
                ->where('i.itemable_type', Packaging::class)->where('i.itemable_id', $p->id)
                ->where('d.type', 'write_off')->where('d.status', '!=', 'draft')
                ->where('d.operation_date', '>=', now()->subDays(7))
                ->sum('i.qty');
            $perDay = $used7 / 7;
            $days = $perDay > 0 ? (int) floor(max(0, $stock) / $perDay) : null;

            $unit = $p->unit ?: 'шт';
            $lines[] = "• <b>" . e(trim($p->name)) . "</b> — лишилось " . $this->num($stock) . " {$unit}"
                . " (" . round(max(0, $stock) / $batch * 100) . "% від поставки " . $this->num($batch) . ")"
                . ($days !== null ? ", вистачить ≈ на {$days} дн." : '')
                . ($last->supplier ? " · постачальник: " . e(trim($last->supplier)) : '');

            if (!$this->option('dry')) {
                DB::table('settings')->updateOrInsert(['key' => $flag], ['value' => (string) $last->doc_id]);
            }
        }

        if (!$lines) {
            $this->info('Нічого замовляти.');
            return self::SUCCESS;
        }

        $text = "📦 <b>Час замовити упаковку</b> — лишилось менше " . round(self::THRESHOLD * 100) . "% від останньої поставки:\n\n"
            . implode("\n", $lines);

        if ($this->option('dry')) {
            $this->line(strip_tags($text));
        } else {
            $telegram->sendToOwner($text);
            $this->info('Надіслано: ' . count($lines) . ' поз.');
        }

        return self::SUCCESS;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
    }
}
