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
    protected $signature = 'telegram:morning-pulse {--dry : Показати повідомлення в консолі, нічого не відправляючи}';
    protected $description = 'Відправити операційний пульс в Telegram о 11:00';

    public function handle(TelegramService $telegram): void
    {
        $today    = Carbon::today();
        $tomorrow = Carbon::tomorrow();

        // Порції
        $portionsToday    = OrderDay::whereDate('date', $today)->count();
        $portionsTomorrow = OrderDay::whereDate('date', $tomorrow)->count();

        // Розбивка по брендах: у дужках — скільки з них індивідуальних.
        $brandNames    = \App\Models\Project::pluck('name', 'slug')->all();
        $brandsToday    = $this->portionsByBrand($today);
        $brandsTomorrow = $this->portionsByBrand($tomorrow);

        // Підписки що закінчуються завтра
        $expiringOrders = Order::whereDate('end_date', $tomorrow)
            ->whereIn('status', ['active', 'new'])
            ->with('client')
            ->get();

        // Борг клієнтів — точна формула як в CRM (борг = -balance - майбутні раціони)
        $futureOrderDays = OrderDay::where('date', '>', $today->format('Y-m-d'))
            ->whereHas('order', fn($q) => $q->whereIn('status', ['active', 'new', 'paused']))
            ->with('order')
            ->get();

        $futureValueByClient = [];
        foreach ($futureOrderDays as $od) {
            $order = $od->order;
            if (!$order) continue;
            $dur      = max(1, (int) $order->duration);
            $dayValue = max(0,
                (float) $order->total_price / $dur
                - (float) $order->discount_amount / $dur
                - (float) $od->discount_amount
            );
            $futureValueByClient[$order->client_id] = ($futureValueByClient[$order->client_id] ?? 0) + $dayValue;
        }

        $debtorClientsCount = 0;
        $totalClientDebt    = 0;

        Client::where('balance', '<', 0)->select('id', 'balance')->each(function ($client) use (&$debtorClientsCount, &$totalClientDebt, $futureValueByClient) {
            $balance     = (float) $client->balance;
            $futureValue = $futureValueByClient[$client->id] ?? 0;
            $debt        = max(0, -$balance - $futureValue);
            if ($debt > 0.01) {
                $debtorClientsCount++;
                $totalClientDebt += $debt;
            }
        });

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
        foreach ($this->brandLines($brandsToday, $brandNames) as $line) {
            $lines[] = $line;
        }
        $lines[] = "  Завтра: <b>{$portionsTomorrow}</b>";
        foreach ($this->brandLines($brandsTomorrow, $brandNames) as $line) {
            $lines[] = $line;
        }
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

        // Борг клієнтів
        $debtTotal = number_format(round($totalClientDebt), 0, '.', ' ');
        if ($debtorClientsCount > 0) {
            $lines[] = "🔴 <b>В борг наїли:</b> {$debtorClientsCount} клієнтів на <b>{$debtTotal} ₴</b>";
        } else {
            $lines[] = "✅ <b>Боргів немає</b>";
        }
        $lines[] = "";

        // Довгі паузи
        $lines[] = "⏸ <b>На паузі > 7 днів:</b> " . $longPausedOrders->count();
        if ($longPausedOrders->isNotEmpty()) {
            foreach ($longPausedOrders->take(5) as $order) {
                // Carbon 3 віддає знакове число з дробом — звідси «-35.69 дн.».
                $days = (int) round(abs(now()->diffInDays($order->updated_at)));
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

        if ($this->option('dry')) {
            $this->line(strip_tags($message));
            $this->info('Сухий прогін — нічого не відправлено.');

            return;
        }

        $telegram->sendToOwnerAndManager($message);

        $this->info('Morning pulse sent.');
    }

    /**
     * Скільки порцій на дату по кожному бренду і скільки з них індивідуальних.
     *
     * @return \Illuminate\Support\Collection<int, object{project: ?string, total: int, individual: int}>
     */
    protected function portionsByBrand(Carbon $date)
    {
        return OrderDay::query()
            ->join('orders', 'orders.id', '=', 'order_days.order_id')
            ->whereDate('order_days.date', $date)
            ->groupBy('orders.project')
            ->orderByDesc('total')
            ->selectRaw('orders.project as project')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN orders.menu_type = 'individual' THEN 1 ELSE 0 END) as individual")
            ->get();
    }

    /**
     * Рядки розбивки. Бренд без індивідуальних дужок не отримує — щоб
     * не засмічувати зведення нулями.
     *
     * @return array<int, string>
     */
    protected function brandLines($brands, array $names): array
    {
        $out = [];

        foreach ($brands as $b) {
            $label = $names[$b->project] ?? ($b->project ?: '—');
            $ind   = (int) $b->individual;
            $out[] = "    • {$label}: {$b->total}" . ($ind > 0 ? " ({$ind} інд.)" : '');
        }

        return $out;
    }
}
