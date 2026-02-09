<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On; // 🔥 Необхідно для слухача подій
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\CalorieRange;
use App\Models\TariffPrice;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use App\Services\ScheduleService;

class OrderCalendar extends Component
{
    // Замовлення може бути null (при створенні)
    public ?Order $order = null;
    
    public $year;
    public $month;

    // Масив для зберігання вибраних днів при створенні (віртуальний режим)
    public array $virtualDays = [];

    // 🔥 Зберігаємо поточний вибраний тип графіку (оновлюється через подію)
    public ?string $scheduleType = null;

    public function mount(?Order $order = null)
    {
        $this->order = $order;
        $this->year = now()->year;
        $this->month = now()->month;

        // Якщо редагуємо існуюче замовлення
        if ($this->order && $this->order->exists) {
            // Завантажуємо тип графіку з бази
            $this->scheduleType = $this->order->schedule_type;

            // Відкриваємо місяць, де починається замовлення
            $start = $this->order->orderDays()->min('date'); 
            if ($start) {
                $date = Carbon::parse($start);
                $this->year = $date->year;
                $this->month = $date->month;
            }
        }
    }

    /**
     * 🔥 СЛУХАЧ ПОДІЇ: Оновлює графік доставки "на льоту"
     * Викликається з OrderResource, коли змінюють селект
     */
    #[On('update-schedule-type')]
    public function updateScheduleType($type)
    {
        $this->scheduleType = $type;
    }

    public function nextMonth()
    {
        $date = Carbon::create($this->year, $this->month, 1)->addMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function prevMonth()
    {
        $date = Carbon::create($this->year, $this->month, 1)->subMonth();
        $this->year = $date->year;
        $this->month = $date->month;
    }

    public function toggleDay($dateStr)
    {
        // === ВАРІАНТ 1: ВІРТУАЛЬНИЙ РЕЖИМ (Створення) ===
        if (!$this->order || !$this->order->exists) {
            if (in_array($dateStr, $this->virtualDays)) {
                $this->virtualDays = array_diff($this->virtualDays, [$dateStr]);
            } else {
                $this->virtualDays[] = $dateStr;
            }

            // Оновлюємо масив і відправляємо у форму Filament для оновлення дат/ціни
            $this->virtualDays = array_values($this->virtualDays);
            $this->dispatch('update-selected-days', days: $this->virtualDays);
            return;
        }

        // === ВАРІАНТ 2: РЕАЛЬНИЙ РЕЖИМ (Редагування) ===
        $pricePerDay = $this->calculatePricePerDay();

        $existingDay = OrderDay::where('order_id', $this->order->id)
            ->where('date', $dateStr)
            ->first();

        if ($existingDay) {
            $existingDay->delete();
            
            if ($pricePerDay > 0) {
                $this->order->client->increment('balance', $pricePerDay);
                $this->order->decrement('total_price', $pricePerDay);
            }
            
            Notification::make()
                ->title('День скасовано')
                ->body("Повернуто на баланс: {$pricePerDay} грн")
                ->success()->send();
        } else {
            OrderDay::create([
                'order_id' => $this->order->id,
                'date' => $dateStr
            ]);

            if ($pricePerDay > 0) {
                $this->order->client->decrement('balance', $pricePerDay);
                $this->order->increment('total_price', $pricePerDay);
            }
            
            Notification::make()
                ->title('День додано')
                ->body("Списано з балансу: {$pricePerDay} грн")
                ->success()->send();
        }
        
        $this->updateOrderStatus();
    }

    private function calculatePricePerDay(): float
    {
        if (!$this->order) return 0;

        $calories = $this->order->calories;
        $range = CalorieRange::where('min_kcal', '<=', $calories)
            ->where('max_kcal', '>=', $calories)
            ->first();

        if (!$range) {
            $count = $this->order->orderDays()->count();
            return ($count > 0 && $this->order->total_price > 0) 
                ? round($this->order->total_price / $count) 
                : 0;
        }

        $priceEntry = TariffPrice::where('tariff_id', $this->order->tariff_id)
            ->where('calorie_range_id', $range->id)
            ->first();

        return $priceEntry ? (float)$priceEntry->price_per_day : 0;
    }

    private function updateOrderStatus()
    {
        if (!$this->order) return;

        $hasFuture = $this->order->orderDays()->whereDate('date', '>=', now())->exists();
        
        if (!$hasFuture) {
            $this->order->update(['status' => 'finished']);
        } else {
            if ($this->order->status === 'finished') {
                $this->order->update(['status' => 'active']);
            }
        }
    }

    public function render()
    {
        // 1. Будуємо сітку календаря
        $calendarMonth = Carbon::create($this->year, $this->month, 1);
        $startOfGrid = $calendarMonth->copy()->startOfMonth()->startOfWeek();
        $endOfGrid = $calendarMonth->copy()->endOfMonth()->endOfWeek();

        $daysInGrid = [];
        for ($date = $startOfGrid->copy(); $date->lte($endOfGrid); $date->addDay()) {
            $daysInGrid[] = $date->copy();
        }

        // 2. Отримуємо активні дні
        if ($this->order && $this->order->exists) {
            $activeDays = OrderDay::where('order_id', $this->order->id)
                ->whereBetween('date', [$startOfGrid->format('Y-m-d'), $endOfGrid->format('Y-m-d')])
                ->get() // Отримуємо колекцію моделей
                ->pluck('date') // Отримуємо колекцію дат (можуть бути Carbon об'єктами)
                ->map(function ($date) {
                    // 🔥 ЗАЛІЗОБЕТОННЕ ПЕРЕТВОРЕННЯ В РЯДОК
                    return is_object($date) && method_exists($date, 'format') 
                        ? $date->format('Y-m-d') 
                        : (string)$date;
                })
                ->toArray();
        } else {
            $activeDays = $this->virtualDays;
        }

        $events = [];
        // Отримуємо тип доставки з динамічної змінної
        $isEveningDelivery = ScheduleService::isEvening($this->scheduleType);

        foreach ($activeDays as $dateItem) {
            // Гарантуємо, що працюємо з рядком для ключа масиву
            $eatDateStr = is_object($dateItem) ? $dateItem->format('Y-m-d') : $dateItem;
            $eatDate = Carbon::parse($eatDateStr);
            
            // 1. Їсть
            $events[$eatDateStr][] = ['icon' => '🍽️', 'color' => 'bg-yellow-100 text-yellow-800', 'title' => 'Раціон'];

            // 2. Готуємо (вчора)
            $prepDate = $eatDate->copy()->subDay()->format('Y-m-d');
            $events[$prepDate][] = ['icon' => '👨‍🍳', 'color' => 'bg-blue-100 text-blue-800', 'title' => 'Готуємо'];
            
            // 3. Веземо
            if ($isEveningDelivery) {
                $events[$prepDate][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-800', 'title' => 'Веземо'];
            } else {
                $events[$eatDateStr][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-800', 'title' => 'Веземо'];
            }
        }

        return view('livewire.order-calendar', [
            'daysInGrid' => $daysInGrid,
            'calendarMonth' => $calendarMonth,
            'events' => $events,
            'activeDays' => $activeDays, 
        ]);
    }
}