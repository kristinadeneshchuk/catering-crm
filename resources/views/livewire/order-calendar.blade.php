<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700 p-4 mt-4">
    @if(empty($startDateStr) || empty($scheduleType))
        <div class="text-center text-gray-500 py-4 text-sm">
            👇 Оберіть "Дату початку" та "Графік", щоб побачити розклад
        </div>
    @else
        {{-- ЗАГОЛОВОК З КНОПКАМИ --}}
        <div class="flex items-center justify-between mb-4 px-2">
            
            {{-- Кнопка НАЗАД --}}
            <button wire:click="prevMonth" type="button" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition">
                <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            </button>

            <div class="font-bold text-lg capitalize text-gray-900 dark:text-white">
                {{ $calendarMonth ? $calendarMonth->locale('uk')->translatedFormat('F Y') : '' }}
            </div>

            {{-- Кнопка ВПЕРЕД --}}
            <button wire:click="nextMonth" type="button" class="p-1 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-full transition">
                <svg class="w-5 h-5 text-gray-600 dark:text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </button>
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
                    $isCurrentMonth = $day->month === $calendarMonth->month;

                    // Стилі
                    if ($isCurrentMonth) {
                        $cellBg = 'bg-white dark:bg-gray-800';
                        $textColor = $isToday ? 'text-orange-600' : 'text-gray-700 dark:text-gray-200';
                        $opacityClass = ''; 
                    } else {
                        $cellBg = 'bg-gray-50 dark:bg-gray-900'; 
                        $textColor = 'text-gray-400 dark:text-gray-600';
                        $opacityClass = 'opacity-60';
                    }

                    $borderClass = $isToday 
                        ? 'border-orange-400 bg-orange-50 dark:bg-orange-900/20' 
                        : 'border-gray-100 dark:border-gray-700';
                @endphp
                
                <div class="min-h-[6rem] border {{ $borderClass }} {{ $cellBg }} rounded-lg p-1 flex flex-col relative transition">
                    <div class="text-xs font-bold mb-1 {{ $textColor }}">
                        {{ $day->day }}
                    </div>

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