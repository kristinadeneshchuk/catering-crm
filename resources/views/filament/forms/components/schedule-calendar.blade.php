@php
    // Отримуємо змінні
    $events = [];
    $calendarMonth = null;
    $daysInGrid = [];

    if (!empty($startDateStr) && !empty($scheduleType)) {
        $start = \Carbon\Carbon::parse($startDateStr);
        $calendarMonth = $start->copy();

        $isEvening = \App\Services\ScheduleService::isEvening($scheduleType);

        for ($i = 0; $i < $duration; $i++) {
            $eatDate = $start->copy()->addDays($i);
            $eatKey = $eatDate->format('Y-m-d');

            // 🍽️ Їсть
            $events[$eatKey][] = ['icon' => '🍽️', 'color' => 'bg-yellow-100 text-yellow-700', 'label' => 'Їсть'];

            if ($isEvening) {
                // ВЕЧІР
                $prepDate = $eatDate->copy()->subDay(); 
                $prepKey = $prepDate->format('Y-m-d');

                $events[$prepKey][] = ['icon' => '👨‍🍳', 'color' => 'bg-blue-100 text-blue-700', 'label' => 'Готуємо'];
                $events[$prepKey][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-700', 'label' => 'Веземо'];
            } else {
                // РАНОК
                $cookDate = $eatDate->copy()->subDay();
                $cookKey = $cookDate->format('Y-m-d');
                
                $events[$cookKey][] = ['icon' => '👨‍🍳', 'color' => 'bg-blue-100 text-blue-700', 'label' => 'Готуємо'];
                $events[$eatKey][] = ['icon' => '🚚', 'color' => 'bg-green-100 text-green-700', 'label' => 'Веземо'];
            }
        }
    }

    if ($calendarMonth) {
        $startOfGrid = $calendarMonth->copy()->startOfMonth()->startOfWeek();
        $endOfGrid = $calendarMonth->copy()->endOfMonth()->endOfWeek();

        for ($date = $startOfGrid->copy(); $date->lte($endOfGrid); $date->addDay()) {
            $daysInGrid[] = $date->copy();
        }
    }
@endphp

<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700 p-4 mt-4">
    @if(empty($startDateStr) || empty($scheduleType))
        <div class="text-center text-gray-500 py-4 text-sm">
            👇 Оберіть "Дату початку" та "Графік", щоб побачити розклад
        </div>
    @else
        {{-- ЗАГОЛОВОК --}}
        <div class="text-center font-bold text-lg mb-4 capitalize text-gray-900 dark:text-white">
            {{ $calendarMonth->locale('uk')->translatedFormat('F Y') }}
        </div>

        {{-- ДНІ ТИЖНЯ --}}
        <div class="grid grid-cols-7 gap-1 mb-2 text-center text-xs font-medium text-gray-400 uppercase">
            <div>Пн</div><div>Вт</div><div>Ср</div><div>Чт</div><div>Пт</div><div>Сб</div><div>Нд</div>
        </div>

        {{-- КАЛЕНДАР --}}
        <div class="grid grid-cols-7 gap-1">
            @foreach($daysInGrid as $day)
                @php
                    $dateKey = $day->format('Y-m-d');
                    $dayEvents = $events[$dateKey] ?? [];
                    $isToday = $day->isToday();
                    
                    // Перевіряємо місяць
                    $isCurrentMonth = $day->month === $calendarMonth->month;

                    // --- ВИПРАВЛЕНА ЛОГІКА СТИЛІВ ---
                    if ($isCurrentMonth) {
                        // Поточний місяць: Білий (світла тема) / Темно-сірий (темна тема)
                        $cellBg = 'bg-white dark:bg-gray-800';
                        $textColor = $isToday ? 'text-orange-600' : 'text-gray-700 dark:text-gray-200';
                        $opacityClass = ''; // Повна видимість
                    } else {
                        // Інший місяць: Сірий (світла тема) / Майже чорний (темна тема)
                        // dark:bg-gray-900 виправить проблему білих квадратів
                        $cellBg = 'bg-gray-50 dark:bg-gray-900'; 
                        $textColor = 'text-gray-400 dark:text-gray-600';
                        $opacityClass = 'opacity-60'; // Робимо трохи тьмяним
                    }

                    // Рамка (сьогодні виділяємо помаранчевим)
                    $borderClass = $isToday 
                        ? 'border-orange-400 bg-orange-50 dark:bg-orange-900/20' 
                        : 'border-gray-100 dark:border-gray-700';
                @endphp
                
                <div class="min-h-[6rem] border {{ $borderClass }} {{ $cellBg }} rounded-lg p-1 flex flex-col relative hover:shadow-md transition">
                    
                    {{-- Число --}}
                    <div class="text-xs font-bold mb-1 {{ $textColor }}">
                        {{ $day->day }}
                    </div>

                    {{-- ПОДІЇ --}}
                    <div class="flex flex-col gap-1 overflow-y-auto max-h-[5rem] custom-scrollbar {{ $opacityClass }}">
                        @foreach($dayEvents as $evt)
                            <div class="flex items-center gap-2 w-full {{ $evt['color'] }} rounded px-1.5 py-1 text-[11px] font-bold leading-tight" title="{{ $evt['label'] }}">
                                <span>{{ $evt['icon'] }}</span>
                                <span class="truncate">{{ $evt['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- ЛЕГЕНДА --}}
        <div class="mt-4 flex flex-wrap gap-4 text-xs text-gray-500 justify-center border-t pt-3">
            <div class="flex items-center gap-2"><span class="text-lg">👨‍🍳</span> Готування</div>
            <div class="flex items-center gap-2"><span class="text-lg">🚚</span> Доставка</div>
            <div class="flex items-center gap-2"><span class="text-lg">🍽️</span> Їсть</div>
        </div>
    @endif
</div>