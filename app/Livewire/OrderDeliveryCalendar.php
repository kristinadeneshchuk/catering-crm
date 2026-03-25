<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Order;
use App\Models\OrderDay;
use App\Models\ClientAddress;
use Carbon\Carbon;
use Filament\Notifications\Notification;

class OrderDeliveryCalendar extends Component
{
    public Order $order;

    public int $year;
    public int $month;

    public ?string $selectedDate = null;
    public ?int $selectedDayId = null;

    // Поля форми для вибраного дня
    public string $address = '';
    public string $address_entrance = '';
    public string $address_apartment = '';
    public string $address_floor = '';
    public string $delivery_comment = '';

    // Знижка на день
    public ?string $discount_type = null;
    public ?string $discount_value = null;

    // Вибір адреси клієнта
    public ?int $selectedAddressId = null;

    public function mount(Order $order): void
    {
        $this->order = $order;
        $this->year  = now()->year;
        $this->month = now()->month;

        // Відкриваємо місяць де є перший день замовлення
        $first = $order->orderDays()->min('date');
        if ($first) {
            $date        = Carbon::parse($first);
            $this->year  = $date->year;
            $this->month = $date->month;
        }
    }

    public function nextMonth(): void
    {
        $date        = Carbon::create($this->year, $this->month)->addMonth();
        $this->year  = $date->year;
        $this->month = $date->month;
    }

    public function prevMonth(): void
    {
        $date        = Carbon::create($this->year, $this->month)->subMonth();
        $this->year  = $date->year;
        $this->month = $date->month;
    }

    public function selectDay(string $dateStr): void
    {
        $day = OrderDay::where('order_id', $this->order->id)
            ->where('date', $dateStr)
            ->first();

        if (!$day) return;

        $this->selectedDate     = $dateStr;
        $this->selectedDayId    = $day->id;

        // Заповнюємо форму: якщо є своя адреса — беремо її, інакше дефолтну адресу клієнта
        $client = $this->order->client;
        $defaultAddr = $client?->addresses()->where('is_default', true)->first()
            ?? $client?->addresses()->first();

        $this->address          = $day->address          ?? $defaultAddr?->address ?? '';
        $this->address_entrance = $day->address_entrance ?? $defaultAddr?->address_entrance ?? '';
        $this->address_apartment= $day->address_apartment ?? $defaultAddr?->address_apartment ?? '';
        $this->address_floor    = $day->address_floor    ?? $defaultAddr?->address_floor ?? '';
        $this->delivery_comment = $day->delivery_comment ?? $defaultAddr?->delivery_comment ?? $client?->delivery_comment ?? '';
        $this->selectedAddressId = null;
        $this->discount_type    = $day->discount_type;
        $this->discount_value   = $day->discount_value !== null ? (string) $day->discount_value : null;
    }

    public function selectClientAddress(int $addressId): void
    {
        $addr = ClientAddress::find($addressId);
        if (!$addr) return;

        $this->selectedAddressId  = $addressId;
        $this->address            = $addr->address;
        $this->address_entrance   = $addr->address_entrance ?? '';
        $this->address_apartment  = $addr->address_apartment ?? '';
        $this->address_floor      = $addr->address_floor ?? '';
        $this->delivery_comment   = $addr->delivery_comment ?? '';
    }

    public function saveDay(): void
    {
        if (!$this->selectedDayId) return;

        $day = OrderDay::find($this->selectedDayId);
        if (!$day) return;

        $day->update([
            'address'          => $this->address ?: null,
            'address_entrance' => $this->address_entrance ?: null,
            'address_apartment'=> $this->address_apartment ?: null,
            'address_floor'    => $this->address_floor ?: null,
            'delivery_comment' => $this->delivery_comment ?: null,
            'discount_type'    => $this->discount_type ?: null,
            'discount_value'   => ($this->discount_type && $this->discount_value !== null && $this->discount_value !== '')
                ? (float) $this->discount_value
                : null,
        ]);

        Notification::make()
            ->title('Збережено')
            ->success()
            ->send();
    }

    public function resetDayAddress(): void
    {
        if (!$this->selectedDayId) return;

        $day = OrderDay::find($this->selectedDayId);
        if (!$day) return;

        $day->update([
            'address'          => null,
            'address_entrance' => null,
            'address_apartment'=> null,
            'address_floor'    => null,
            'delivery_comment' => null,
        ]);

        // Повертаємо адресу клієнта у форму
        $client = $this->order->client;
        $this->address          = $client?->address ?? '';
        $this->address_entrance = $client?->address_entrance ?? '';
        $this->address_apartment= $client?->address_apartment ?? '';
        $this->address_floor    = $client?->address_floor ?? '';
        $this->delivery_comment = $client?->delivery_comment ?? '';

        Notification::make()
            ->title('Адресу скинуто до адреси клієнта')
            ->success()
            ->send();
    }

    public function render()
    {
        $calendarMonth = Carbon::create($this->year, $this->month, 1);
        $startOfGrid   = $calendarMonth->copy()->startOfMonth()->startOfWeek();
        $endOfGrid     = $calendarMonth->copy()->endOfMonth()->endOfWeek();

        $daysInGrid = [];
        for ($d = $startOfGrid->copy(); $d->lte($endOfGrid); $d->addDay()) {
            $daysInGrid[] = $d->copy();
        }

        $orderDays = OrderDay::where('order_id', $this->order->id)
            ->get()
            ->keyBy(fn ($day) => Carbon::parse($day->date)->format('Y-m-d'));

        $clientAddresses = $this->order->client
            ? $this->order->client->addresses()->orderByDesc('is_default')->get()
            : collect();

        return view('livewire.order-delivery-calendar', [
            'daysInGrid'      => $daysInGrid,
            'calendarMonth'   => $calendarMonth,
            'orderDays'       => $orderDays,
            'clientAddresses' => $clientAddresses,
        ]);
    }
}
