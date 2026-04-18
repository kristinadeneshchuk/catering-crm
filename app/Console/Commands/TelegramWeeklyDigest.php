<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderCall;
use App\Models\OrderDay;
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
        $weekStart = Carbon::now()->startOfWeek()->subWeek(); // минулий понеділок
        $weekEnd   = Carbon::now()->startOfWeek()->subDay();  // минула неділя

        $lines = [];
        $lines[] = "🟢 <b>Тижневий дайджест</b> — " . $weekStart->format('d.m') . " – " . $weekEnd->format('d.m.Y');
        $lines[] = "";

        // --- Retention rate ---
        // Замовлення що закінчились минулого тижня
        $expiredOrders = Order::whereBetween('end_date', [$weekStart, $weekEnd])
            ->whereIn('status', ['active', 'completed', 'finished', 'paused'])
            ->with('client.orders')
            ->get();

        $expiredCount  = $expiredOrders->count();
        $renewedCount  = 0;

        foreach ($expiredOrders as $order) {
            $hasNewOrder = $order->client?->orders
                ->where('start_date', '>', $order->end_date)
                ->isNotEmpty();

            if ($hasNewOrder) {
                $renewedCount++;
            }
        }

        if ($expiredCount > 0) {
            $retentionRate = round($renewedCount / $expiredCount * 100);
            $retentionIcon = $retentionRate >= 70 ? "✅" : ($retentionRate >= 50 ? "⚠️" : "🔴");
            $lines[] = "{$retentionIcon} <b>Retention:</b> {$renewedCount}/{$expiredCount} = <b>{$retentionRate}%</b>";
        } else {
            $lines[] = "📊 <b>Retention:</b> підписки не закінчувались";
        }
        $lines[] = "";

        // --- Топ-3 причини відмов ---
        $refusals = OrderCall::whereBetween('created_at', [$weekStart, $weekEnd->endOfDay()])
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

        // --- Середній чек і тривалість ---
        $ordersThisWeek = Order::whereBetween('created_at', [$weekStart, $weekEnd->endOfDay()])->get();
        $avgPrice    = $ordersThisWeek->avg('final_price') ?? 0;
        $avgDuration = $ordersThisWeek->avg('duration') ?? 0;
        $newOrdersCount = $ordersThisWeek->count();

        $avgPriceFormatted = number_format((float) $avgPrice, 0, '.', ' ');
        $lines[] = "💳 <b>Нових замовлень:</b> {$newOrdersCount}";
        $lines[] = "   Середній чек: <b>{$avgPriceFormatted} ₴</b>";
        $lines[] = "   Середня тривалість: <b>" . round((float) $avgDuration) . " дн.</b>";
        $lines[] = "";

        // --- Завантаженість кухні ---
        $maxCapacity = (int) \App\Models\Setting::where('key', 'kitchen_max_capacity')->value('value');
        if ($maxCapacity > 0) {
            $daysInPeriod = $weekStart->diffInDays($weekEnd) + 1;
            $totalPortions = OrderDay::whereBetween('date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])->count();
            $avgPortions   = $daysInPeriod > 0 ? round($totalPortions / $daysInPeriod) : 0;
            $loadPercent   = round($avgPortions / $maxCapacity * 100);
            $loadIcon      = $loadPercent >= 90 ? "🔴" : ($loadPercent >= 70 ? "🟡" : "🟢");
            $lines[] = "{$loadIcon} <b>Завантаженість кухні:</b> {$avgPortions}/{$maxCapacity} порц./день = <b>{$loadPercent}%</b>";
            $lines[] = "";
        }

        // --- Концентрація ризику: топ-5 клієнтів ---
        $totalRevenue = (float) Transaction::whereBetween('date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
            ->where('type', 'income')
            ->whereNotNull('order_id')
            ->sum('amount');

        if ($totalRevenue > 0) {
            $topClients = Transaction::whereBetween('date', [$weekStart->format('Y-m-d'), $weekEnd->format('Y-m-d')])
                ->where('type', 'income')
                ->whereNotNull('order_id')
                ->join('orders', 'transactions.order_id', '=', 'orders.id')
                ->join('clients', 'orders.client_id', '=', 'clients.id')
                ->select('clients.name', DB::raw('SUM(transactions.amount) as revenue'))
                ->groupBy('clients.id', 'clients.name')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get();

            $top5Revenue  = $topClients->sum('revenue');
            $top5Percent  = round($top5Revenue / $totalRevenue * 100);
            $riskIcon     = $top5Percent >= 40 ? "⚠️" : "✅";
            $totalFormatted = number_format($totalRevenue, 0, '.', ' ');

            $lines[] = "{$riskIcon} <b>Виручка за тиждень:</b> {$totalFormatted} ₴";
            $lines[] = "   Топ-5 клієнтів: <b>{$top5Percent}%</b> від загальної";

            foreach ($topClients as $i => $c) {
                $rev = number_format((float) $c->revenue, 0, '.', ' ');
                $pct = round((float) $c->revenue / $totalRevenue * 100);
                $lines[] = "   " . ($i + 1) . ". {$c->name} — {$rev} ₴ ({$pct}%)";
            }
            $lines[] = "";
        }

        // --- Джерела залучення ---
        $newClients = Client::whereBetween('created_at', [$weekStart, $weekEnd->endOfDay()])
            ->select('sales_source', DB::raw('COUNT(*) as cnt'))
            ->groupBy('sales_source')
            ->orderByDesc('cnt')
            ->get();

        $totalNew = $newClients->sum('cnt');
        $lines[] = "📣 <b>Нових клієнтів:</b> {$totalNew}";
        if ($newClients->isNotEmpty()) {
            foreach ($newClients as $source) {
                $label = $source->sales_source ?: 'Невідомо';
                $lines[] = "   • {$label}: {$source->cnt}";
            }
        } else {
            $lines[] = "   Нових не було";
        }

        $message = implode("\n", $lines);
        $telegram->sendToOwner($message);

        $this->info('Weekly digest sent.');
    }
}
