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

    // Modal адреси
    public bool $showAddressModal = false;
    public ?string $modalDate = null;
    public ?int $modalDayId = null;
    public string $modalAddress = '';
    public string $modalEntrance = '';
    public string $modalApartment = '';
    public string $modalFloor = '';
    public string $modalComment = '';
    public string $addressSearch = '';
    public array $addressResults = [];
    public array $clientAddresses = [];
    public ?int $selectedClientAddressId = null;

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
        if (\Carbon\Carbon::parse($dateStr)->lt(now()->startOfDay())) {
            Notification::make()
                ->title('Минуле не змінити')
                ->body('Ви не можете редагувати дні, що вже минули.')
                ->warning()
                ->send();
            return;
        }

        // === ВАРІАНТ 1: ВІРТУАЛЬНИЙ РЕЖИМ (Створення) ===
        if (!$this->order || !$this->order->exists) {
            if (in_array($dateStr, $this->virtualDays)) {
                $this->virtualDays = array_diff($this->virtualDays, [$dateStr]);
            } else {
                $this->virtualDays[] = $dateStr;
            }
            $this->virtualDays = array_values($this->virtualDays);
            sort($this->virtualDays);
            $this->dispatch('update-selected-days', days: $this->virtualDays);
            return;
        }

        // === ВАРІАНТ 2: РЕАЛЬНИЙ РЕЖИМ (Редагування) ===
        $pricePerDay = $this->calculatePricePerDay();

        $existingDay = OrderDay::where('order_id', $this->order->id)
            ->where('date', $dateStr)
            ->first();

        if ($existingDay) {
            // Відкриваємо modal для редагування адреси
            $this->openAddressModal($dateStr, $existingDay);
            return;
        } else {
            // 🔥 ВИДАЛЯЄМО: Тільки створюємо запис.
            // Гроші спишуться автоматично через Observer моделі OrderDay
            OrderDay::create([
                'order_id' => $this->order->id,
                'date' => $dateStr
            ]);

            Notification::make()
                ->title('День додано')
                ->body("Списано з балансу: {$pricePerDay} грн")
                ->success()->send();
        }
        
        $this->updateOrderStatus();

        // Оновлюємо список днів для форми
        $updatedDays = OrderDay::where('order_id', $this->order->id)
            ->orderBy('date')
            ->get()
            ->map(fn ($day) => Carbon::parse($day->date)->format('Y-m-d'))
            ->values()
            ->toArray();
            
        $this->dispatch('update-selected-days', days: $updatedDays);
    }

    public function openAddressModal(string $dateStr, OrderDay $day): void
    {
        $client = $this->order?->client;
        $this->modalDate      = $dateStr;
        $this->modalDayId     = $day->id;
        $this->modalAddress   = $day->address   ?? '';
        $this->modalEntrance  = $day->address_entrance  ?? '';
        $this->modalApartment = $day->address_apartment ?? '';
        $this->modalFloor     = $day->address_floor     ?? '';
        $this->modalComment   = $day->delivery_comment  ?? '';
        $this->addressSearch  = '';
        $this->addressResults = [];
        $this->selectedClientAddressId = null;

        // Завантажуємо адреси клієнта
        $this->clientAddresses = $client
            ? $client->addresses()->orderByDesc('is_default')->get()->toArray()
            : [];

        $this->showAddressModal = true;
    }

    public function selectClientAddress(int $id): void
    {
        $this->selectedClientAddressId = $id;
        $addr = collect($this->clientAddresses)->firstWhere('id', $id);
        if ($addr) {
            $this->modalAddress   = $addr['address'] ?? '';
            $this->modalEntrance  = $addr['entrance'] ?? '';
            $this->modalApartment = $addr['apartment'] ?? '';
            $this->modalFloor     = $addr['floor'] ?? '';
        }
    }

    public function closeAddressModal(): void
    {
        $this->showAddressModal = false;
        $this->modalDate = null;
        $this->modalDayId = null;
        $this->addressResults = [];
    }

    public function searchAddress(): void
    {
        if (strlen($this->addressSearch) < 3) {
            $this->addressResults = [];
            return;
        }
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $this->addressSearch . ', Київ', 'format' => 'json', 'limit' => 6, 'countrycodes' => 'ua',
        ]);
        $response = @file_get_contents($url, false, stream_context_create([
            'http' => ['header' => "User-Agent: CRM/1.0\r\n"],
        ]));
        $results = $response ? (json_decode($response, true) ?? []) : [];
        $this->addressResults = array_column($results, 'display_name');
    }

    public function selectAddress(string $address): void
    {
        $this->modalAddress  = $address;
        $this->addressSearch = $address;
        $this->addressResults = [];
    }

    public function saveAddress(): void
    {
        if (!$this->modalDayId) return;
        $day = OrderDay::find($this->modalDayId);
        if (!$day) return;

        $day->update([
            'address'          => $this->modalAddress ?: null,
            'address_entrance' => $this->modalEntrance ?: null,
            'address_apartment'=> $this->modalApartment ?: null,
            'address_floor'    => $this->modalFloor ?: null,
            'delivery_comment' => $this->modalComment ?: null,
        ]);

        $this->closeAddressModal();
        Notification::make()->title('Адресу збережено')->success()->send();
    }

    public function resetAddress(): void
    {
        if (!$this->modalDayId) return;
        $day = OrderDay::find($this->modalDayId);
        if (!$day) return;

        $day->update([
            'address' => null, 'address_entrance' => null,
            'address_apartment' => null, 'address_floor' => null, 'delivery_comment' => null,
        ]);

        $this->closeAddressModal();
        Notification::make()->title('Адресу скинуто до адреси клієнта')->success()->send();
    }

    public function removeDay(): void
    {
        if (!$this->modalDayId || !$this->order) return;
        $day = OrderDay::find($this->modalDayId);
        if (!$day) return;

        $pricePerDay = $this->calculatePricePerDay();
        $day->delete();
        $this->updateOrderStatus();
        $this->closeAddressModal();

        $updatedDays = OrderDay::where('order_id', $this->order->id)
            ->orderBy('date')->get()
            ->map(fn ($d) => Carbon::parse($d->date)->format('Y-m-d'))
            ->values()->toArray();
        $this->dispatch('update-selected-days', days: $updatedDays);

        Notification::make()->title('День скасовано')->body("Повернуто на баланс: {$pricePerDay} грн")->success()->send();
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