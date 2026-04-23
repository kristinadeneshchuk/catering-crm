<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderDay;
use App\Models\Packaging;
use App\Models\StockDocument;
use App\Models\Transaction;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TelegramWeeklyDigest extends Command
{
    protected $signature = 'telegram:weekly-digest';
    protected $description = 'Відправити тижневий дайджест в Telegram щопонеділка о 09:00';

    public function handle(TelegramService $telegram): void
    {
        $weekStart = Carbon::now()->startOfWeek()->subWeek();
        $weekEnd   = Carbon::now()->startOfWeek()->subDay();
        $prevStart = $weekStart->copy()->subWeek();
        $prevEnd   = $weekEnd->copy()->subWeek();

        $wS = $weekStart->format('Y-m-d');
        $wE = $weekEnd->format('Y-m-d');
        $pS = $prevStart->format('Y-m-d');
        $pE = $prevEnd->format('Y-m-d');

        // ── Повідомлення 1: Основні метрики ──────────────────────────────
        $telegram->sendToOwnerAndManager($this->buildMainReport($weekStart, $weekEnd, $prevStart, $prevEnd, $wS, $wE, $pS, $pE));

        // ── Повідомлення 2: Закупки та склад ─────────────────────────────
        $telegram->sendToOwnerAndManager($this->buildStockReport($weekStart, $weekEnd, $wS, $wE, $pS, $pE));

        // ── Повідомлення 3: Не профільні оплати ──────────────────────────
        $telegram->sendToOwnerAndManager($this->buildNonStandardPayments($wS, $wE));

        $this->info('Weekly digest sent (3 messages).');
    }

    // ─────────────────────────────────────────────────────────────────────
    // ПОВІДОМЛЕННЯ 1: Основні метрики
    // ─────────────────────────────────────────────────────────────────────
    private function buildMainReport($weekStart, $weekEnd, $prevStart, $prevEnd, $wS, $wE, $pS, $pE): string
    {
        $lines = [];
        $lines[] = "🟢 <b>Тижневий дайджест</b> — " . $weekStart->format('d.m') . " – " . $weekEnd->format('d.m.Y');
        $lines[] = "";

        // --- Retention ---
        $expiredOrders = Order::whereBetween('end_date', [$wS, $wE])
            ->whereIn('status', ['active', 'completed', 'finished', 'paused'])
            ->with('client.orders')
            ->get();

        $expiredCount = $expiredOrders->count();
        $renewedCount = $expiredOrders->filter(fn ($o) =>
            $o->client?->orders->where('start_date', '>', $o->end_date)->isNotEmpty()
        )->count();

        $prevExpired  = Order::whereBetween('end_date', [$pS, $pE])->whereIn('status', ['active', 'completed', 'finished', 'paused'])->with('client.orders')->get();
        $prevRenewed  = $prevExpired->filter(fn ($o) => $o->client?->orders->where('start_date', '>', $o->end_date)->isNotEmpty())->count();
        $prevRetention = $prevExpired->count() > 0 ? round($prevRenewed / $prevExpired->count() * 100) : 0;
        $currRetention = $expiredCount > 0 ? round($renewedCount / $expiredCount * 100) : 0;

        $retentionDiff = $currRetention - $prevRetention;
        $retentionArrow = $retentionDiff >= 0 ? "▲+" : "▼";
        $retentionIcon  = $currRetention >= 70 ? "✅" : ($currRetention >= 50 ? "⚠️" : "🔴");
        if ($expiredCount > 0) {
            $lines[] = "{$retentionIcon} <b>Retention:</b> {$renewedCount}/{$expiredCount} = <b>{$currRetention}%</b> ({$retentionArrow}{$retentionDiff}% до мин.)";
        } else {
            $lines[] = "📊 <b>Retention:</b> підписки не закінчувались";
        }
        $lines[] = "";

        // --- Причини відмов ---
        $refusals = OrderCall::whereBetween('created_at', [$wS, $weekEnd->endOfDay()])
            ->where('status', 'refused')
            ->whereNotNull('refusal_reason')
            ->where('refusal_reason', '!=', '')
            ->select('refusal_reason', DB::raw('COUNT(*) as cnt'))
            ->groupBy('refusal_reason')
            ->orderByDesc('cnt')
            ->limit(3)
            ->get();

        $lines[] = "❌ <b>Топ причини відмов:</b>";
        if ($refusals->isNotEmpty()) {
            foreach ($refusals as $i => $r) {
                $lines[] = "  " . ($i + 1) . ". {$r->refusal_reason} ({$r->cnt})";
            }
        } else {
            $lines[] = "  Немає відмов за тиждень";
        }
        $lines[] = "";

        // --- Нові замовлення, середній чек ---
        $ordersThisWeek = Order::whereBetween('created_at', [$wS, $weekEnd->endOfDay()])->get();
        $avgPrice       = round((float) $ordersThisWeek->avg('final_price'));
        $avgDuration    = round((float) $ordersThisWeek->avg('duration'));
        $lines[] = "💳 <b>Нових замовлень:</b> {$ordersThisWeek->count()}";
        $lines[] = "   Середній чек: <b>" . number_format($avgPrice, 0, '.', ' ') . " ₴</b>";
        $lines[] = "   Середня тривалість: <b>{$avgDuration} дн.</b>";
        $lines[] = "";

        // --- Виручка W/W ---
        $revenue     = (float) Transaction::whereBetween('date', [$wS, $wE])->where('type', 'income')->whereNotNull('order_id')->sum('amount');
        $prevRevenue = (float) Transaction::whereBetween('date', [$pS, $pE])->where('type', 'income')->whereNotNull('order_id')->sum('amount');
        $revDiff     = $prevRevenue > 0 ? round(($revenue - $prevRevenue) / $prevRevenue * 100) : 0;
        $revArrow    = $revDiff >= 0 ? "▲+" : "▼";
        $revIcon     = $revDiff >= 0 ? "✅" : "⚠️";
        $lines[] = "{$revIcon} <b>Виручка:</b> " . number_format($revenue, 0, '.', ' ') . " ₴ ({$revArrow}{$revDiff}% до мин.)";
        $lines[] = "";

        // --- Раціонів виготовлено W/W ---
        $portions     = OrderDay::whereBetween('date', [$wS, $wE])->count();
        $prevPortions = OrderDay::whereBetween('date', [$pS, $pE])->count();
        $portDiff     = $prevPortions > 0 ? round(($portions - $prevPortions) / $prevPortions * 100) : 0;
        $portArrow    = $portDiff >= 0 ? "▲+" : "▼";
        $lines[] = "📦 <b>Раціонів:</b> {$portions} ({$portArrow}{$portDiff}% до мин.)";
        $lines[] = "";

        // --- Концентрація ризику: топ-5 клієнтів ---
        if ($revenue > 0) {
            $topClients = Transaction::whereBetween('date', [$wS, $wE])
                ->where('type', 'income')->whereNotNull('order_id')
                ->join('orders', 'transactions.order_id', '=', 'orders.id')
                ->join('clients', 'orders.client_id', '=', 'clients.id')
                ->select('clients.name', DB::raw('SUM(transactions.amount) as rev'))
                ->groupBy('clients.id', 'clients.name')
                ->orderByDesc('rev')
                ->limit(5)->get();

            $top5 = $topClients->sum('rev');
            $top5pct = round($top5 / $revenue * 100);
            $riskIcon = $top5pct >= 40 ? "⚠️" : "✅";
            $lines[] = "{$riskIcon} <b>Топ-5 клієнтів:</b> {$top5pct}% виручки";
            foreach ($topClients as $i => $c) {
                $pct = round((float) $c->rev / $revenue * 100);
                $lines[] = "   " . ($i + 1) . ". {$c->name} — " . number_format((float) $c->rev, 0, '.', ' ') . " ₴ ({$pct}%)";
            }
            $lines[] = "";
        }

        // --- Джерела залучення ---
        $newClients = Client::whereBetween('created_at', [$wS, $weekEnd->endOfDay()])
            ->select('sales_source', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sales_source')->orderByDesc('cnt')->get();

        $totalNew = $newClients->sum('cnt');
        $lines[] = "📣 <b>Нових клієнтів:</b> {$totalNew}";
        foreach ($newClients as $src) {
            $label = $src->sales_source ?: 'Невідомо';
            $lines[] = "   • {$label}: {$src->cnt}";
        }

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ПОВІДОМЛЕННЯ 2: Закупки та склад
    // ─────────────────────────────────────────────────────────────────────
    private function buildStockReport($weekStart, $weekEnd, $wS, $wE, $pS, $pE): string
    {
        $lines = [];
        $lines[] = "🏭 <b>Закупки та склад</b> — " . $weekStart->format('d.m') . " – " . $weekEnd->format('d.m.Y');
        $lines[] = "";

        $ingredientClass = \App\Models\Ingredient::class;
        $packagingClass  = \App\Models\Packaging::class;

        // --- Закупки продукції по накладним ---
        $ingredientPurchases = DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $ingredientClass)
            ->whereBetween('stock_documents.operation_date', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->selectRaw('SUM(stock_document_items.qty * stock_document_items.price) as total, SUM(stock_document_items.qty) as total_qty')
            ->first();

        $ingredientTotal = (float) ($ingredientPurchases->total ?? 0);
        $lines[] = "🥦 <b>Закупки продукції:</b> " . number_format($ingredientTotal, 0, '.', ' ') . " ₴";

        // Порівняння з минулим тижнем
        $prevIngredientTotal = (float) DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $ingredientClass)
            ->whereBetween('stock_documents.operation_date', [Carbon::parse($pS)->startOfDay(), Carbon::parse($pE)->endOfDay()])
            ->sum(DB::raw('stock_document_items.qty * stock_document_items.price'));

        if ($prevIngredientTotal > 0) {
            $diff = round(($ingredientTotal - $prevIngredientTotal) / $prevIngredientTotal * 100);
            $arrow = $diff >= 0 ? "▲+" : "▼";
            $icon  = $diff > 10 ? "⚠️" : ($diff >= 0 ? "→" : "✅");
            $lines[] = "   {$icon} {$arrow}{$diff}% до мин. тижня";
        }

        // Залишок продукції на складі
        $stockValue = (float) Ingredient::selectRaw('SUM(stock * price_per_kg)')->value(DB::raw('SUM(stock * price_per_kg)'));
        $lines[] = "   Залишок на складі: <b>" . number_format($stockValue, 0, '.', ' ') . " ₴</b>";
        $lines[] = "";

        // --- Закупки тари по накладним ---
        $packagingPurchases = DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $packagingClass)
            ->whereBetween('stock_documents.operation_date', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->selectRaw('SUM(stock_document_items.qty * stock_document_items.price) as total')
            ->first();

        $packagingTotal = (float) ($packagingPurchases->total ?? 0);
        $lines[] = "📦 <b>Закупки тари:</b> " . number_format($packagingTotal, 0, '.', ' ') . " ₴";

        $prevPackagingTotal = (float) DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $packagingClass)
            ->whereBetween('stock_documents.operation_date', [Carbon::parse($pS)->startOfDay(), Carbon::parse($pE)->endOfDay()])
            ->sum(DB::raw('stock_document_items.qty * stock_document_items.price'));

        if ($prevPackagingTotal > 0) {
            $diff = round(($packagingTotal - $prevPackagingTotal) / $prevPackagingTotal * 100);
            $arrow = $diff >= 0 ? "▲+" : "▼";
            $icon  = $diff > 10 ? "⚠️" : ($diff >= 0 ? "→" : "✅");
            $lines[] = "   {$icon} {$arrow}{$diff}% до мин. тижня";
        }

        $packStockValue = (float) Packaging::selectRaw('SUM(stock * price)')->value(DB::raw('SUM(stock * price)'));
        $lines[] = "   Залишок на складі: <b>" . number_format($packStockValue, 0, '.', ' ') . " ₴</b>";
        $lines[] = "";

        // --- Зростання вартості інгредієнтів ---
        // Беремо середню ціну закупки цього тижня vs минулого
        $thisWeekPrices = DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $ingredientClass)
            ->whereBetween('stock_documents.operation_date', [$weekStart->startOfDay(), $weekEnd->endOfDay()])
            ->select('stock_document_items.itemable_id', DB::raw('AVG(stock_document_items.price) as avg_price'), DB::raw('SUM(stock_document_items.qty) as total_qty'))
            ->groupBy('stock_document_items.itemable_id')
            ->get()->keyBy('itemable_id');

        $prevWeekPrices = DB::table('stock_document_items')
            ->join('stock_documents', 'stock_document_items.stock_document_id', '=', 'stock_documents.id')
            ->where('stock_documents.type', 'receipt')
            ->where('stock_document_items.itemable_type', $ingredientClass)
            ->whereBetween('stock_documents.operation_date', [Carbon::parse($pS)->startOfDay(), Carbon::parse($pE)->endOfDay()])
            ->select('stock_document_items.itemable_id', DB::raw('AVG(stock_document_items.price) as avg_price'))
            ->groupBy('stock_document_items.itemable_id')
            ->get()->keyBy('itemable_id');

        $priceGrowth = [];
        foreach ($thisWeekPrices as $id => $curr) {
            if (isset($prevWeekPrices[$id]) && (float) $prevWeekPrices[$id]->avg_price > 0) {
                $pct = round(((float) $curr->avg_price - (float) $prevWeekPrices[$id]->avg_price) / (float) $prevWeekPrices[$id]->avg_price * 100, 1);
                if ($pct > 0) {
                    $priceGrowth[$id] = ['pct' => $pct, 'qty' => (float) $curr->total_qty];
                }
            }
        }
        arsort($priceGrowth);

        if (!empty($priceGrowth)) {
            $ingredientIds   = array_keys($priceGrowth);
            $ingredientNames = Ingredient::whereIn('id', $ingredientIds)->pluck('name', 'id');

            $lines[] = "📈 <b>Подорожчали інгредієнти:</b>";
            $count = 0;
            foreach ($priceGrowth as $id => $data) {
                if ($count >= 10) break;
                $name = $ingredientNames[$id] ?? "#{$id}";
                $lines[] = "  ▲+{$data['pct']}% — {$name}";
                $count++;
            }
        } else {
            $lines[] = "✅ <b>Ціни інгредієнтів:</b> без суттєвих змін";
        }
        $lines[] = "";

        // --- Топ-20 найбільш використовуваних інгредієнтів ---
        $topIngredients = DB::table('dish_ingredients')
            ->join('ingredients', 'dish_ingredients.ingredient_id', '=', 'ingredients.id')
            ->select('ingredients.id', 'ingredients.name', 'ingredients.price_per_kg', DB::raw('COUNT(DISTINCT dish_ingredients.dish_id) as dish_count'))
            ->groupBy('ingredients.id', 'ingredients.name', 'ingredients.price_per_kg')
            ->orderByDesc('dish_count')
            ->limit(20)
            ->get();

        $lines[] = "🔍 <b>Топ-20 інгредієнтів (для оптимізації закупки):</b>";
        foreach ($topIngredients as $i => $ing) {
            $price  = number_format((float) $ing->price_per_kg, 2);
            $growth = isset($priceGrowth[$ing->id]) ? " ▲+{$priceGrowth[$ing->id]['pct']}%" : "";
            $lines[] = "  " . ($i + 1) . ". {$ing->name} — {$price} ₴/кг ({$ing->dish_count} страв){$growth}";
        }

        return implode("\n", $lines);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ПОВІДОМЛЕННЯ 3: Не профільні оплати
    // ─────────────────────────────────────────────────────────────────────
    private function buildNonStandardPayments(string $wS, string $wE): string
    {
        $lines = [];
        $lines[] = "💸 <b>Не профільні оплати</b> — " . Carbon::parse($wS)->format('d.m') . " – " . Carbon::parse($wE)->format('d.m.Y');
        $lines[] = "";

        // Витрати не пов'язані із закупками та зарплатою
        $standardExpenseCategories = ['Закупівля', 'Списання зі складу', 'Скасування оплати'];

        $nonStandardExpenses = Transaction::whereBetween('date', [$wS, $wE])
            ->where('type', 'expense')
            ->whereNull('order_id')
            ->whereNull('employee_id')
            ->whereNull('stock_document_id')
            ->whereNotIn('category', $standardExpenseCategories)
            ->orderByDesc('amount')
            ->get(['category', 'amount', 'comment', 'date']);

        $expenseTotal = $nonStandardExpenses->sum('amount');

        if ($nonStandardExpenses->isNotEmpty()) {
            $lines[] = "🔴 <b>Витрати (не профіль):</b> " . number_format($expenseTotal, 0, '.', ' ') . " ₴";
            foreach ($nonStandardExpenses as $t) {
                $amount  = number_format((float) $t->amount, 0, '.', ' ');
                $date    = Carbon::parse($t->date)->format('d.m');
                $comment = $t->comment ? " — {$t->comment}" : '';
                $lines[] = "  • [{$date}] {$t->category}: <b>{$amount} ₴</b>{$comment}";
            }
        } else {
            $lines[] = "✅ <b>Не профільних витрат немає</b>";
        }
        $lines[] = "";

        // Доходи не пов'язані із замовленнями
        $nonStandardIncome = Transaction::whereBetween('date', [$wS, $wE])
            ->where('type', 'income')
            ->whereNull('order_id')
            ->whereNull('stock_document_id')
            ->orderByDesc('amount')
            ->get(['category', 'amount', 'comment', 'date']);

        $incomeTotal = $nonStandardIncome->sum('amount');

        if ($nonStandardIncome->isNotEmpty()) {
            $lines[] = "🟢 <b>Інші надходження:</b> " . number_format($incomeTotal, 0, '.', ' ') . " ₴";
            foreach ($nonStandardIncome as $t) {
                $amount  = number_format((float) $t->amount, 0, '.', ' ');
                $date    = Carbon::parse($t->date)->format('d.m');
                $comment = $t->comment ? " — {$t->comment}" : '';
                $lines[] = "  • [{$date}] {$t->category}: <b>{$amount} ₴</b>{$comment}";
            }
        } else {
            $lines[] = "✅ <b>Інших надходжень немає</b>";
        }

        return implode("\n", $lines);
    }
}
