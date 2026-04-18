<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Transaction;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

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

        $message = implode("\n", $lines);
        $telegram->sendToOwner($message);

        $this->info('Evening summary sent.');
    }
}
