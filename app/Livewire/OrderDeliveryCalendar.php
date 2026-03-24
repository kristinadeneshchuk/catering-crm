<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Order;
use App\Models\OrderDay;
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

    // Автодоповнення адреси
    public string $addressSearch = '';
    public array $addressResults = [];

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

        // Заповнюємо форму: якщо є своя адреса — беремо її, інакше адресу клієнта
        $client = $this->order->client;
        $this->address          = $day->address          ?? $client?->address ?? '';
        $this->address_entrance = $day->address_entrance ?? $client?->address_entrance ?? '';
        $this->address_apartment= $day->address_apartment ?? $client?->address_apartment ?? '';
        $this->address_floor    = $day->address_floor    ?? $client?->address_floor ?? '';
        $this->delivery_comment = $day->delivery_comment ?? $client?->delivery_comment ?? '';
        $this->addressSearch    = $this->address;
        $this->addressResults   = [];
    }

    public function searchAddress(): void
    {
        if (strlen($this->addressSearch) < 3) {
            $this->addressResults = [];
            return;
        }

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'            => $this->addressSearch . ', Київ',
            'format'       => 'json',
            'limit'        => 6,
            'countrycodes' => 'ua',
        ]);

        $response = @file_get_contents($url, false, stream_context_create([
            'http' => ['header' => "User-Agent: CRM/1.0\r\n"],
        ]));

        if (!$response) {
            $this->addressResults = [];
            return;
        }

        $results = json_decode($response, true) ?? [];
        $this->addressResults = array_column($results, 'display_name');
    }

    public function selectAddress(string $address): void
    {
        $this->address       = $address;
        $this->addressSearch = $address;
        $this->addressResults = [];
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
        ]);

        Notification::make()
            ->title('Адресу збережено')
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
        $this->addressSearch    = $this->address;

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

        return view('livewire.order-delivery-calendar', [
            'daysInGrid'    => $daysInGrid,
            'calendarMonth' => $calendarMonth,
            'orderDays'     => $orderDays,
        ]);
    }
}
