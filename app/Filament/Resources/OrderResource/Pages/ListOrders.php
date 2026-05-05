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
     * Спрацьовує при відкритті списку замовлень. Слугує як "ледача"
     * щоденна нормалізація: коли час іде вперед, минулі замовлення мають
     * перевалідувати статус (днів >= today може вже не бути).
     *
     * Логіка статусу — у Order::recomputeStatus() (paused — sticky).
     * Призупинені/завершені сюди не потрапляють: paused — це ручне рішення,
     * finished/completed — стабільний кінцевий стан, який оживляє лише
     * OrderDayObserver при додаванні нового дня.
     */
    public function mount(): void
    {
        Order::whereNotIn('status', ['finished', 'completed', 'paused'])
            ->get()
            ->each(fn (Order $order) => $order->recomputeStatus());

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}