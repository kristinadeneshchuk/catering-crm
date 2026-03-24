<div class="flex gap-6 p-4">

    {{-- ЛІВА ПАНЕЛЬ: Календар --}}
    <div class="w-80 shrink-0">
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm overflow-hidden">

            {{-- Навігація --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                <button type="button" wire:click="prevMonth" class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </button>
                <span class="font-semibold text-sm text-gray-800 dark:text-gray-200">
                    {{ $calendarMonth->translatedFormat('F Y') }}
                </span>
                <button type="button" wire:click="nextMonth" class="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-800">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
            </div>

            {{-- Дні тижня --}}
            <div class="grid grid-cols-7 text-center text-xs font-medium text-gray-500 dark:text-gray-400 px-2 pt-2">
                @foreach(['Пн','Вт','Ср','Чт','Пт','Сб','Нд'] as $dow)
                    <div class="py-1">{{ $dow }}</div>
                @endforeach
            </div>

            {{-- Сітка --}}
            <div class="grid grid-cols-7 gap-0.5 px-2 pb-3">
                @foreach($daysInGrid as $day)
                    @php
                        $dateStr     = $day->format('Y-m-d');
                        $isOrderDay  = isset($orderDays[$dateStr]);
                        $isSelected  = $dateStr === $this->selectedDate;
                        $isToday     = $day->isToday();
                        $isOtherMonth= $day->month !== $calendarMonth->month;
                        $hasCustomAddr = $isOrderDay && $orderDays[$dateStr]->address !== null;
                    @endphp
                    <button
                        type="button"
                        @if($isOrderDay) wire:click="selectDay('{{ $dateStr }}')" @endif
                        class="
                            relative aspect-square flex flex-col items-center justify-center rounded-lg text-xs transition-all
                            {{ $isOtherMonth ? 'opacity-30' : '' }}
                            {{ $isSelected ? 'bg-primary-500 text-white shadow' : '' }}
                            {{ $isOrderDay && !$isSelected ? 'bg-primary-100 dark:bg-primary-900 text-primary-700 dark:text-primary-300 hover:bg-primary-200 cursor-pointer font-semibold' : '' }}
                            {{ !$isOrderDay ? 'text-gray-400 dark:text-gray-600 cursor-default' : '' }}
                            {{ $isToday && !$isSelected ? 'ring-2 ring-primary-400' : '' }}
                        "
                    >
                        {{ $day->day }}
                        @if($hasCustomAddr && !$isSelected)
                            <span class="absolute bottom-0.5 left-1/2 -translate-x-1/2 w-1 h-1 rounded-full bg-orange-400"></span>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Легенда --}}
            <div class="px-3 pb-3 flex flex-col gap-1 text-xs text-gray-500 dark:text-gray-400">
                <div class="flex items-center gap-2">
                    <span class="w-3 h-3 rounded bg-primary-100 dark:bg-primary-900 inline-block"></span>
                    День доставки
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-1.5 h-1.5 rounded-full bg-orange-400 inline-block"></span>
                    Своя адреса
                </div>
            </div>
        </div>
    </div>

    {{-- ПРАВА ПАНЕЛЬ: Деталі дня --}}
    <div class="flex-1">
        @if($this->selectedDate && isset($orderDays[$this->selectedDate]))
            @php $day = $orderDays[$this->selectedDate]; @endphp

            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm p-5">

                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-base font-semibold text-gray-800 dark:text-gray-200">
                        🚚 {{ \Carbon\Carbon::parse($this->selectedDate)->translatedFormat('l, d F Y') }}
                    </h3>
                    @if($day->address !== null)
                        <span class="text-xs px-2 py-1 rounded-full bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300">
                            Своя адреса
                        </span>
                    @else
                        <span class="text-xs px-2 py-1 rounded-full bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            Адреса клієнта
                        </span>
                    @endif
                </div>

                {{-- Вибір збереженої адреси клієнта --}}
                @if($clientAddresses->count() > 0)
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Адреси клієнта</label>
                    <div class="flex flex-col gap-2">
                        @foreach($clientAddresses as $addr)
                        <button
                            type="button"
                            wire:click="selectClientAddress({{ $addr->id }})"
                            class="text-left px-3 py-2 rounded-lg border text-sm transition-all
                                {{ $selectedAddressId === $addr->id
                                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900 text-primary-700 dark:text-primary-300'
                                    : 'border-gray-200 dark:border-gray-700 hover:border-primary-300 hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300' }}"
                        >
                            <span class="font-medium">{{ $addr->label }}</span>
                            @if($addr->is_default) <span class="text-xs text-green-600 dark:text-green-400 ml-1">• за замовч.</span> @endif
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 truncate">{{ $addr->address }}</div>
                        </button>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Адреса (ручний ввід або заповнюється при виборі) --}}
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Адреса</label>
                    <input
                        type="text"
                        wire:model="address"
                        placeholder="вул. Хрещатик, 1, Київ"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500 focus:border-primary-500"
                    />
                </div>

                {{-- Під'їзд / Кв / Поверх --}}
                <div class="grid grid-cols-3 gap-3 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Під'їзд</label>
                        <input type="text" wire:model="address_entrance" placeholder="—"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Кв/офіс</label>
                        <input type="text" wire:model="address_apartment" placeholder="—"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Поверх</label>
                        <input type="text" wire:model="address_floor" placeholder="—"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"/>
                    </div>
                </div>

                {{-- Коментар --}}
                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Коментар для доставки</label>
                    <textarea wire:model="delivery_comment" rows="2" placeholder="Домофон, під'їзд, особливі вказівки..."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"></textarea>
                </div>

                {{-- Кнопки --}}
                <div class="flex gap-3">
                    <button type="button" wire:click="saveDay"
                        class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors">
                        Зберегти адресу
                    </button>
                    @if($day->address !== null)
                        <button type="button" wire:click="resetDayAddress"
                            class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 text-sm font-medium rounded-lg transition-colors">
                            Скинути до адреси клієнта
                        </button>
                    @endif
                </div>
            </div>
        @else
            <div class="rounded-xl border border-dashed border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900 p-10 flex flex-col items-center justify-center text-center">
                <div class="text-4xl mb-3">📅</div>
                <p class="text-gray-500 dark:text-gray-400 text-sm">Виберіть день доставки в календарі щоб змінити адресу</p>
            </div>
        @endif
    </div>

</div>
