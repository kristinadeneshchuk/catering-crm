<?php

namespace App\Livewire;

use Livewire\Component;
use Carbon\Carbon;
use App\Services\ScheduleService;

class OrderCalendar extends Component
{
    // Параметри, які приходять з форми
    public $startDateStr;
    public $duration;
    public $scheduleType;

    // Змінна для перемикання місяців (0 = поточний, 1 = наступний...)
    public $monthOffset = 0;

    public function nextMonth()
    {
        $this->monthOffset++;
    }

    public function prevMonth()
    {
        $this->monthOffset--;
    }

    public function render()
    {
        $events = [];
        $calendarMonth = null;
        $daysInGrid = [];

        if (!empty($this->startDateStr) && !empty($this->scheduleType)) {
            $start = Carbon::parse($this->startDateStr);
            
            // 1. Визначаємо місяць для ВІДОБРАЖЕННЯ (з урахуванням кнопок)
            $calendarMonth = $start->copy()->addMonths($this->monthOffset);

            // 2. РОЗРАХУНОК ПОДІЙ (Завжди від реальної дати старту)
            $isEvening = ScheduleService::isEvening($this->scheduleType);

            for ($i = 0; $i < $this->duration; $i++) {
                $eatDate = $start->copy()->addDays($i); // Події рахуємо від старту замовлення
                $eatKey = $eatDate->format('Y-m-d');

                $events[$eatKey][] = ['icon' => '🍽️', 'color' => 'bg-yellow-100 text-yellow-700', 'label' => 'Їсть'];

                if ($isEvening) {
                    $prepDate = $eatDate->copy()->subDay(); 
                    $prepKey = $prepDate->format('Y-m-d');
                    $events[$prepKey][] = ['icon' => '👨‍🍳', 'color' => 'bg-blue-100 text-blue-700', 'label' => 'Готуємо'];
                    $events[$prepKey][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-700', 'label' => 'Веземо'];
                } else {
                    $cookDate = $eatDate->copy()->subDay();
                    $cookKey = $cookDate->format('Y-m-d');
                    $events[$cookKey][] = ['icon' => '👨‍🍳', 'color' => 'bg-blue-100 text-blue-700', 'label' => 'Готуємо'];
                    $events[$eatKey][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-700', 'label' => 'Веземо'];
                }
            }

            // 3. ГЕНЕРАЦІЯ СІТКИ (Для відображуваного місяця)
            $startOfGrid = $calendarMonth->copy()->startOfMonth()->startOfWeek();
            $endOfGrid = $calendarMonth->copy()->endOfMonth()->endOfWeek();

            for ($date = $startOfGrid->copy(); $date->lte($endOfGrid); $date->addDay()) {
                $daysInGrid[] = $date->copy();
            }
        }

        return view('livewire.order-calendar', [
            'events' => $events,
            'calendarMonth' => $calendarMonth,
            'daysInGrid' => $daysInGrid,
        ]);
    }
}