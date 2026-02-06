<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Client;
use App\Models\Transaction;

class RecalculateClientBalances extends Command
{
    /**
     * Ім'я команди для запуску в терміналі
     */
    protected $signature = 'app:recalculate-balances';

    /**
     * Опис команди
     */
    protected $description = 'Перераховує баланси клієнтів та оновлює статуси оплати замовлень (FIFO)';

    public function handle()
    {
        $this->info('Починаємо перерахунок балансів...');

        // Проходимо по всіх клієнтах
        Client::chunk(100, function ($clients) {
            foreach ($clients as $client) {
                $this->line("Обробка клієнта: {$client->name} (ID: {$client->id})...");

                // 1. Рахуємо суму всіх оплат (Income)
                // Використовуємо whereHas, щоб переконатися, що транзакції прив'язані до цього клієнта через замовлення
                $income = Transaction::whereHas('order', fn($q) => $q->where('client_id', $client->id))
                    ->where('type', 'income')
                    ->sum('amount');

                // 2. Рахуємо суму всіх повернень (Refund)
                $refund = Transaction::whereHas('order', fn($q) => $q->where('client_id', $client->id))
                    ->where('type', 'refund')
                    ->sum('amount');

                // 3. Рахуємо вартість усіх замовлень
                $ordersCost = $client->orders->sum('total_price');

                // 4. Оновлюємо баланс (Всі гроші - Всі замовлення)
                $realBalance = $income - $refund - $ordersCost;
                
                // updateQuietly важливий, щоб не викликати події, які знову запустять перерахунок
                $client->updateQuietly(['balance' => $realBalance]);

                $this->info(" -> Баланс виправлено на: {$realBalance} грн");

                // 5. Запускаємо логіку FIFO (проставляємо статуси оплати)
                $this->recalculateOrdersFifo($client, $income - $refund);
            }
        });

        $this->info('Успішно завершено!');
    }

    private function recalculateOrdersFifo($client, $wallet)
    {
        // Беремо замовлення від старих до нових
        $orders = $client->orders()->orderBy('start_date', 'asc')->get();

        foreach ($orders as $order) {
            // Якщо ціна 0 (тестове замовлення), ставимо оплачено
            if ($order->total_price <= 0) {
                 if (!$order->is_paid) {
                     $order->updateQuietly(['is_paid' => true]);
                     $this->line(" -> Замовлення #{$order->id} (0 грн) -> ОПЛАЧЕНО (безкоштовне)");
                 }
                 continue;
            }

            if ($wallet >= $order->total_price) {
                // Грошей вистачає
                if (!$order->is_paid) {
                    $order->updateQuietly(['is_paid' => true]);
                    $this->info(" -> Замовлення #{$order->id} ({$order->total_price} грн) -> ОПЛАЧЕНО");
                }
                $wallet -= $order->total_price;
            } else {
                // Грошей не вистачає
                if ($order->is_paid) {
                    $order->updateQuietly(['is_paid' => false]);
                    $this->error(" -> Замовлення #{$order->id} ({$order->total_price} грн) -> НЕ ОПЛАЧЕНО (бракує коштів)");
                }
                // Віднімаємо залишок (йдемо в мінус по віртуальному гаманцю)
                $wallet -= $order->total_price;
            }
        }
    }
}