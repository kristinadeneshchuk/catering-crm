<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\OrderDay;
use App\Models\CalorieRange;
use App\Models\TariffPrice;
use Carbon\Carbon;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;

    // Змінна для зберігання днів між етапами збереження
    protected array $customSelectedDays = [];

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * КРОК 1: Обробка даних ПЕРЕД створенням замовлення
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // 1. Намагаємося дістати дні з буфера
        $buffer = $data['selected_days_buffer'] ?? '[]';
        $selectedDays = json_decode($buffer, true);

        // Якщо дні є - зберігаємо їх у змінну класу
        if (!empty($selectedDays) && is_array($selectedDays)) {
            $this->customSelectedDays = $selectedDays;
            
            // Жорстко оновлюємо тривалість, щоб вона збігалася з кількістю вибраних днів
            $data['duration'] = count($selectedDays);
        }

        // 2. Видаляємо буфер, щоб не було помилки SQL (такої колонки немає в БД)
        unset($data['selected_days_buffer']);

        // 3. Рахуємо ціну (Server-Side страховка)
        $calories = (int) ($data['calories'] ?? 0);
        $tariffId = $data['tariff_id'] ?? null;
        $duration = (int) ($data['duration'] ?? 1);
        
        $pricePerDay = 0;

        if ($calories && $tariffId) {
            $range = CalorieRange::where('min_kcal', '<=', $calories)
                ->where('max_kcal', '>=', $calories)->first();

            if ($range) {
                $priceEntry = TariffPrice::where('tariff_id', $tariffId)
                    ->where('calorie_range_id', $range->id)->first();

                if ($priceEntry) {
                    $pricePerDay = (float) $priceEntry->price_per_day;
                }
            }
        }

        $data['total_price'] = $pricePerDay * $duration;

        return $data;
    }

    /**
     * КРОК 2: Створення днів ПІСЛЯ створення замовлення
     */
    protected function afterCreate(): void
    {
        $order = $this->record;

        // 🔥 ПЕРЕВІРКА: Чи ми зберегли "рвані" дні на кроці 1?
        if (!empty($this->customSelectedDays)) {
            
            // ВАРІАНТ А: Створюємо дні з календаря
            foreach ($this->customSelectedDays as $date) {
                OrderDay::firstOrCreate([
                    'order_id' => $order->id,
                    'date' => $date
                ]);
            }

        } elseif ($order->start_date && $order->duration > 0) {
            
            // ВАРІАНТ Б: Якщо календар був пустий, створюємо дні підряд
            $startDate = Carbon::parse($order->start_date);
            for ($i = 0; $i < $order->duration; $i++) {
                OrderDay::firstOrCreate([
                    'order_id' => $order->id,
                    'date' => $startDate->copy()->addDays($i)->format('Y-m-d')
                ]);
            }
        }
        
        $this->updateOrderStatus($order);
    }

    private function updateOrderStatus($order)
    {
        $hasFuture = $order->orderDays()->whereDate('date', '>=', now())->exists();
        
        if ($hasFuture) {
             $count = \App\Models\Order::where('client_id', $order->client_id)->count();
             $status = ($count === 1) ? 'new' : 'active';
             
             if ($order->status !== $status) {
                 $order->update(['status' => $status]);
             }
        }
    }
}