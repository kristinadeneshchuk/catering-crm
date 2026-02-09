<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\Order; // Підключаємо модель

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Цей метод спрацьовує автоматично при відкритті сторінки.
     * Тут ми оновлюємо статуси на основі календаря (order_days).
     */
    public function mount(): void
    {
        // 1. Беремо тільки ті замовлення, які ще не завершені
        // (Щоб не перевіряти архівні замовлення і не вантажити базу)
        $orders = Order::whereNotIn('status', ['finished', 'completed'])->get();

        foreach ($orders as $order) {
            // 2. Перевіряємо, чи є у замовлення дні ПОЧИНАЮЧИ ВІД СЬОГОДНІ
            // Використовуємо зв'язок orderDays(), який ми додали в модель
            $hasFutureDays = $order->orderDays()
                ->whereDate('date', '>=', now()->format('Y-m-d'))
                ->exists();

            if (!$hasFutureDays) {
                // Якщо днів у майбутньому немає — замовлення завершене
                // (Навіть якщо воно було 'active' або 'paused')
                if ($order->status !== 'finished') {
                    $order->update(['status' => 'finished']);
                }
            } else {
                // Якщо дні є — визначаємо: це "Новий" чи "Активний"?
                
                // Рахуємо всі замовлення цього клієнта
                $clientOrdersCount = Order::where('client_id', $order->client_id)->count();

                // Якщо це перше і єдине замовлення — статус "new", інакше "active"
                $newStatus = ($clientOrdersCount === 1) ? 'new' : 'active';

                // Оновлюємо статус тільки якщо він відрізняється від поточного
                if ($order->status !== $newStatus) {
                    $order->update(['status' => $newStatus]);
                }
            }
        }

        // 3. Запускаємо стандартну логіку сторінки
        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}