<div class="rounded-xl border border-gray-200 bg-white shadow-sm dark:bg-gray-800 dark:border-gray-700 p-4 mt-4">
    
    {{-- ЗАГОЛОВОК З КНОПКАМИ --}}
    <div class="flex items-center justify-between mb-4 px-2">
        <button wire:click="prevMonth" type="button" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition group">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-800 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        </button>

        <div class="font-bold text-lg capitalize text-gray-900 dark:text-white">
            {{ $calendarMonth->locale('uk')->translatedFormat('F Y') }}
        </div>

        <button wire:click="nextMonth" type="button" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg transition group">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-800 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
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
                
                $isActive = in_array($dateKey, $activeDays); 
                $isToday = $day->isToday();
                $isCurrentMonth = $day->month === $calendarMonth->month;
                $isPast = $day->lt(now()->startOfDay());

                $containerClasses = "min-h-[6rem] border rounded-lg p-1 flex flex-col relative transition select-none";
                $blockEffects = ""; 

                if ($isPast) {
                    $bgClass = "bg-gray-50 dark:bg-gray-800"; 
                    $borderClass = "border-gray-200 dark:border-gray-700 border-dashed"; 
                    $textClass = "text-gray-400 dark:text-gray-500"; 
                    $cursorClass = "cursor-not-allowed";
                    $hoverClass = "";
                    $blockEffects = "opacity-50 grayscale"; 
                } 
                elseif ($isActive) {
                    $bgClass = "bg-green-50 dark:bg-green-900/30";
                    $borderClass = "border-green-500 ring-1 ring-green-500 dark:border-green-500";
                    $textClass = "text-green-800 dark:text-green-300 font-bold";
                    $cursorClass = "cursor-pointer";
                    $hoverClass = "hover:shadow-md";
                } 
                elseif ($isCurrentMonth) {
                    $bgClass = "bg-white dark:bg-gray-800";
                    $borderClass = "border-gray-200 dark:border-gray-700";
                    $textClass = "text-gray-700 dark:text-gray-200";
                    $cursorClass = "cursor-pointer";
                    $hoverClass = "hover:border-blue-300 dark:hover:border-blue-500 hover:shadow-sm";
                } 
                else {
                    // 🔥 ВИПРАВЛЕНО: Прибираємо білу рамку для днів іншого місяця
                    $bgClass = "bg-transparent dark:bg-transparent"; 
                    $borderClass = "border-transparent dark:border-transparent"; 
                    $textClass = "text-gray-300 dark:text-gray-700";
                    $cursorClass = "cursor-pointer opacity-30";
                    $hoverClass = "hover:bg-gray-50/50 dark:hover:bg-gray-700/30";
                }

                if ($isToday) {
                    $textClass = "text-orange-600 font-extrabold";
                    if (!$isActive && !$isPast) {
                        $borderClass = "border-orange-400 bg-orange-50 dark:bg-orange-900/20";
                    }
                }
            @endphp
            
            <div 
                @if(!$isPast) wire:click="toggleDay('{{ $dateKey }}')" @endif
                class="{{ $containerClasses }} {{ $bgClass }} {{ $borderClass }} {{ $cursorClass }} {{ $hoverClass }} {{ $blockEffects }}"
            >
                <div class="text-xs mb-1 flex justify-between items-center px-1">
                    <span class="{{ $textClass }}">{{ $day->day }}</span>
                    
                    <div class="flex items-center gap-1">
                        @if($isPast)
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 text-gray-400 dark:text-gray-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" />
                            </svg>
                        @endif

                        @if($isActive)
                            <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        @endif
                    </div>
                </div>

                <div class="flex flex-col gap-1 overflow-y-auto max-h-[4.5rem] custom-scrollbar">
                    @foreach($dayEvents as $evt)
                        <div class="flex items-center gap-1.5 w-full {{ $evt['color'] }} rounded px-1.5 py-1 text-[10px] font-bold leading-tight shadow-sm" title="{{ $evt['title'] }}">
                            <span>{{ $evt['icon'] }}</span>
                            <span class="truncate">{{ $evt['title'] }}</span>
                        </div>
                    @endforeach
                </div>

                @if(!$isPast)
                    <div 
                        wire:loading 
                        wire:target="toggleDay('{{ $dateKey }}')" 
                        class="absolute inset-0 bg-white/60 dark:bg-gray-800/60 backdrop-blur-[1px] flex items-center justify-center rounded-lg z-10"
                    >
                        <svg class="animate-spin h-5 w-5 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ЛЕГЕНДА --}}
    <div class="mt-4 flex flex-wrap gap-4 text-xs text-gray-500 dark:text-gray-400 justify-center border-t border-gray-100 dark:border-gray-700 pt-3">
        <div class="flex items-center gap-1"><span class="text-base">👨‍🍳</span> Готуємо</div>
        <div class="flex items-center gap-1"><span class="text-base">🚚</span> Доставка</div>
        <div class="flex items-center gap-1"><span class="text-base">🍽️</span> Їсть</div>
        <div class="flex items-center gap-1 ml-4 opacity-50 grayscale"><svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg> Минуле</div>
    </div>
    
    <div class="text-center mt-2 text-[10px] text-gray-400">
        * Клікніть на дату, щоб додати або видалити день раціону. Баланс оновлюється автоматично.
    </div>
</div>