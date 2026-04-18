<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Order;
use App\Models\OrderDay;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TelegramMorningPulse extends Command
{
    protected $signature = 'telegram:morning-pulse';
    protected $description = 'Відправити операційний пульс в Telegram о 11:00';

    public function handle(TelegramService $telegram): void
    {
        $today    = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // Порції
        $portionsToday    = OrderDay::whereDate('date', $today)->count();
        $portionsTomorrow = OrderDay::whereDate('date', $tomorrow)->count();

        // Підписки що закінчуються завтра
        $expiringOrders = Order::whereDate('end_date', $tomorrow)
            ->whereIn('status', ['active', 'new'])
            ->with('client')
            ->get();

        // Клієнти з від'ємним балансом
        $negativeClients = Client::where('balance', '<', 0)
            ->orderBy('balance')
            ->get(['name', 'balance', 'phone']);

        // Клієнти на паузі більше 7 днів (без дзвінка)
        $longPausedOrders = Order::where('status', 'paused')
            ->where('updated_at', '<=', now()->subDays(7))
            ->with('client')
            ->get();

        // --- Формуємо повідомлення ---
        $lines = [];
        $lines[] = "🔴 <b>Операційний пульс</b> — " . $today->format('d.m.Y');
        $lines[] = "";

        $lines[] = "📦 <b>Порцій:</b>";
        $lines[] = "  Сьогодні: <b>{$portionsToday}</b>";
        $lines[] = "  Завтра: <b>{$portionsTomorrow}</b>";
        $lines[] = "";

        // Підписки що закінчуються
        $lines[] = "⏳ <b>Підписки закінчуються завтра:</b> " . $expiringOrders->count();
        if ($expiringOrders->isNotEmpty()) {
            foreach ($expiringOrders as $order) {
                $name  = $order->client?->name ?? '—';
                $phone = $order->client?->phone ?? '';
                $lines[] = "  • {$name}" . ($phone ? " ({$phone})" : '');
            }
        } else {
            $lines[] = "  ✅ Немає";
        }
        $lines[] = "";

        // Від'ємні баланси
        $lines[] = "🔴 <b>Від'ємний баланс:</b> " . $negativeClients->count();
        if ($negativeClients->isNotEmpty()) {
            foreach ($negativeClients->take(10) as $client) {
                $balance = number_format((float) $client->balance, 0, '.', ' ');
                $lines[] = "  • {$client->name}: <b>{$balance} ₴</b>";
            }
            if ($negativeClients->count() > 10) {
                $lines[] = "  ... і ще " . ($negativeClients->count() - 10);
            }
        } else {
            $lines[] = "  ✅ Всі в плюсі";
        }
        $lines[] = "";

        // Довгі паузи
        $lines[] = "⏸ <b>На паузі > 7 днів:</b> " . $longPausedOrders->count();
        if ($longPausedOrders->isNotEmpty()) {
            foreach ($longPausedOrders->take(5) as $order) {
                $days = now()->diffInDays($order->updated_at);
                $name = $order->client?->name ?? '—';
                $lines[] = "  • {$name} ({$days} дн.)";
            }
            if ($longPausedOrders->count() > 5) {
                $lines[] = "  ... і ще " . ($longPausedOrders->count() - 5);
            }
        } else {
            $lines[] = "  ✅ Таких немає";
        }

        $message = implode("\n", $lines);
        $telegram->sendToOwnerAndManager($message);

        $this->info('Morning pulse sent.');
    }
}
