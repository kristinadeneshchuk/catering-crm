<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TelegramEveningSummary extends Command
{
    protected $signature = 'telegram:evening-summary';
    protected $description = 'Відправити підсумок дня в Telegram о 18:00';

    public function handle(TelegramService $telegram): void
    {
        $today = Carbon::today();

        // Оплати за день
        $payments = Transaction::whereDate('date', $today)
            ->where('type', 'income')
            ->whereNotNull('order_id') // тільки клієнтські оплати
            ->selectRaw('COUNT(*) as count, SUM(amount) as total')
            ->first();

        $paymentCount = (int) ($payments->count ?? 0);
        $paymentTotal = (float) ($payments->total ?? 0);

        // Нові клієнти сьогодні
        $newClients = Client::whereDate('created_at', $today)->count();

        // Клієнти на паузі
        $pausedOrders = Order::where('status', 'paused')->count();

        // Борг по зарплаті
        $salaryDebt = (float) Employee::where('balance', '>', 0)->sum('balance');

        // --- Формуємо повідомлення ---
        $lines = [];
        $lines[] = "🟡 <b>Підсумок дня</b> — " . $today->format('d.m.Y');
        $lines[] = "";

        $totalFormatted = number_format($paymentTotal, 0, '.', ' ');
        $lines[] = "💰 <b>Оплати:</b> {$paymentCount} шт. на <b>{$totalFormatted} ₴</b>";
        $lines[] = "";

        $icon = $newClients > 0 ? "🆕" : "➖";
        $lines[] = "{$icon} <b>Нових клієнтів:</b> {$newClients}";
        $lines[] = "";

        $pauseIcon = $pausedOrders > 3 ? "⚠️" : "⏸";
        $lines[] = "{$pauseIcon} <b>На паузі:</b> {$pausedOrders}";
        $lines[] = "";

        $debtFormatted = number_format($salaryDebt, 0, '.', ' ');
        $debtIcon = $salaryDebt > 0 ? "⚠️" : "✅";
        $lines[] = "{$debtIcon} <b>Борг по зарплаті:</b> {$debtFormatted} ₴";
        $lines[] = "";

        // Не продовжились після закінчення тарифу (закінчились за останні 7 днів, немає нового замовлення)
        $recentlyExpired = Order::whereBetween('end_date', [now()->subDays(7)->format('Y-m-d'), $today->format('Y-m-d')])
            ->whereIn('status', ['active', 'completed', 'finished', 'paused'])
            ->with('client.orders')
            ->get();

        $notRenewed = $recentlyExpired->filter(function ($order) {
            return $order->client && $order->client->orders
                ->where('start_date', '>', $order->end_date)
                ->isEmpty();
        });

        $notRenewedIcon = $notRenewed->count() > 0 ? "⚠️" : "✅";
        $lines[] = "{$notRenewedIcon} <b>Не продовжились після тарифу:</b> {$notRenewed->count()}";
        if ($notRenewed->isNotEmpty()) {
            foreach ($notRenewed->take(5) as $order) {
                $name    = $order->client?->name ?? '—';
                $endDate = Carbon::parse($order->end_date)->format('d.m');
                $lines[] = "  • {$name} (до {$endDate})";
            }
            if ($notRenewed->count() > 5) {
                $lines[] = "  ... і ще " . ($notRenewed->count() - 5);
            }
        }
        $lines[] = "";

        // Клієнти без активності 5+ днів (замовлення закінчилось, немає оплати і немає нового замовлення)
        $cutoff = now()->subDays(5)->format('Y-m-d');
        $inactiveClients = Order::where('end_date', '<=', $cutoff)
            ->whereIn('status', ['active', 'completed', 'finished', 'paused'])
            ->with(['client.orders', 'client.transactions'])
            ->get()
            ->filter(function ($order) use ($cutoff) {
                if (!$order->client) return false;

                $hasNewOrder = $order->client->orders
                    ->where('start_date', '>', $order->end_date)
                    ->isNotEmpty();

                $hasRecentPayment = $order->client->transactions()
                    ->where('type', 'income')
                    ->where('date', '>', $order->end_date)
                    ->exists();

                return !$hasNewOrder && !$hasRecentPayment;
            })
            ->unique(fn ($o) => $o->client_id);

        $inactiveIcon = $inactiveClients->count() > 0 ? "🔕" : "✅";
        $lines[] = "{$inactiveIcon} <b>Без активності 5+ днів:</b> {$inactiveClients->count()}";
        if ($inactiveClients->isNotEmpty()) {
            foreach ($inactiveClients->take(5) as $order) {
                $name    = $order->client?->name ?? '—';
                $phone   = $order->client?->phone ?? '';
                $endDate = Carbon::parse($order->end_date)->format('d.m');
                $days    = Carbon::parse($order->end_date)->diffInDays(now());
                $lines[] = "  • {$name}" . ($phone ? " ({$phone})" : '') . " — {$days} дн. тому";
            }
            if ($inactiveClients->count() > 5) {
                $lines[] = "  ... і ще " . ($inactiveClients->count() - 5);
            }
        }

        $message = implode("\n", $lines);
        $telegram->sendToOwner($message);

        $this->info('Evening summary sent.');
    }
}
