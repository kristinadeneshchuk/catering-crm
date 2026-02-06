<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\Order; // 1. Обов'язково підключаємо модель

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /**
     * Цей метод спрацьовує автоматично при відкритті сторінки.
     * Тут ми оновлюємо статуси "на льоту".
     */
    public function mount(): void
    {
        // 2. Оновлюємо старі замовлення
        Order::whereDate('end_date', '<', now()) // Якщо дата закінчення менша за сьогодні
            ->whereNotIn('status', ['finished', 'completed', 'paused']) // І статус ще не фінальний (і не пауза)
            ->update(['status' => 'finished']); // Ставимо "Завершений"

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