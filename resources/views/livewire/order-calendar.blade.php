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

                if ($isPast && $isActive) {
                    $bgClass = "bg-green-50 dark:bg-green-900/30";
                    $borderClass = "border-green-500 ring-1 ring-green-500 dark:border-green-500 border-dashed";
                    $textClass = "text-green-800 dark:text-green-300 font-bold";
                    $cursorClass = "cursor-pointer";
                    $hoverClass = "hover:shadow-md";
                }
                elseif ($isPast) {
                    $bgClass = "bg-gray-50 dark:bg-gray-800";
                    $borderClass = "border-gray-200 dark:border-gray-700 border-dashed";
                    $textClass = "text-gray-400 dark:text-gray-500";
                    $cursorClass = "cursor-pointer";
                    $hoverClass = "hover:border-blue-300 dark:hover:border-blue-500 hover:shadow-sm";
                    $blockEffects = "";
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
                    $bgClass = "bg-transparent dark:bg-transparent";
                    $borderClass = "border-transparent dark:border-transparent";
                    $textClass = "text-gray-300 dark:text-gray-700";
                    $cursorClass = "cursor-pointer opacity-30";
                    $hoverClass = "hover:bg-gray-50/50 dark:hover:bg-gray-700/30";
                }

                if ($isToday) {
                    $textClass = "text-orange-600 font-extrabold";
                    if (!$isActive) {
                        $borderClass = "border-orange-400 bg-orange-50 dark:bg-orange-900/20";
                    }
                }

                // Перевіряємо чи є кастомна адреса
                $hasCustomAddress = false;
                if ($isActive && $this->order) {
                    $dayModel = $this->order->orderDays->firstWhere('date', $dateKey)
                        ?? $this->order->orderDays->first(fn($d) => \Carbon\Carbon::parse($d->date)->format('Y-m-d') === $dateKey);
                    $hasCustomAddress = $dayModel && $dayModel->address !== null;
                }
            @endphp

            <div
                wire:click="toggleDay('{{ $dateKey }}')"
                class="{{ $containerClasses }} {{ $bgClass }} {{ $borderClass }} {{ $cursorClass }} {{ $hoverClass }} {{ $blockEffects }}"
            >
                <div class="text-xs mb-1 flex justify-between items-center px-1">
                    <span class="{{ $textClass }}">{{ $day->day }}</span>

                    <div class="flex items-center gap-1">
                        @if($isActive)
                            <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                        @endif
                        @if($hasCustomAddress)
                            <span class="w-1.5 h-1.5 rounded-full bg-orange-400 inline-block" title="Своя адреса"></span>
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
        <div class="flex items-center gap-1 ml-4 opacity-50 grayscale"><svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd" /></svg> Минуле</div>
        <div class="flex items-center gap-1"><span class="w-1.5 h-1.5 rounded-full bg-orange-400 inline-block"></span> Своя адреса</div>
    </div>

    <div class="text-center mt-2 text-[10px] text-gray-400">
        * Клікніть на порожню дату — додати день. Клікніть на зелену — змінити адресу.
    </div>

    {{-- MODAL АДРЕСИ --}}
    @if($showAddressModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);">
        <div class="bg-white dark:bg-gray-900 rounded-2xl shadow-2xl w-full max-w-lg flex flex-col" style="max-height: 90vh;" x-data="{ open: false }">

            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                <div>
                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">🚚 Адреса доставки</h3>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                        {{ \Carbon\Carbon::parse($modalDate)->locale('uk')->translatedFormat('l, d F Y') }}
                    </p>
                </div>
                <button type="button" wire:click="closeAddressModal" class="p-2 hover:bg-gray-100 dark:hover:bg-gray-800 rounded-lg">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Body --}}
            <div class="px-6 py-5 space-y-4 overflow-y-auto flex-1 min-h-0">

                {{-- Список адрес клієнта --}}
                @if(count($clientAddresses) > 0)
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Адреса доставки</label>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            @foreach($clientAddresses as $addr)
                                <button type="button"
                                    wire:click="selectClientAddress({{ $addr['id'] }})"
                                    class="w-full text-left px-3 py-2.5 rounded-lg border text-sm transition-colors
                                        {{ $selectedClientAddressId === $addr['id']
                                            ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/30 text-primary-700 dark:text-primary-300'
                                            : 'border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 text-gray-700 dark:text-gray-300' }}"
                                >
                                    <div class="font-medium">{{ $addr['address'] }}</div>
                                    @if($addr['address_entrance'] || $addr['address_apartment'] || $addr['address_floor'])
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            @if($addr['address_entrance']) Під'їзд {{ $addr['address_entrance'] }} @endif
                                            @if($addr['address_apartment']) · Кв {{ $addr['address_apartment'] }} @endif
                                            @if($addr['address_floor']) · Пов {{ $addr['address_floor'] }} @endif
                                        </div>
                                    @endif
                                    @if($addr['is_default'])
                                        <span class="text-xs text-primary-500">За замовчуванням</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    </div>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500">У клієнта немає збережених адрес. Додайте їх у картці клієнта.</p>
                @endif

                {{-- Коментар --}}
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Коментар для доставки</label>
                    <textarea wire:model="modalComment" rows="2" placeholder="Домофон, під'їзд, особливі вказівки..."
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500"></textarea>
                </div>

                {{-- Час доставки на цей день --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">⏰ Час доставки на цей день</label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">
                        За замовчуванням: <span class="font-medium text-gray-600 dark:text-gray-300">{{ $this->order?->delivery_time ?? 'не вказано' }}</span>
                    </p>
                    <select wire:model="modalDeliveryTime"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
                        <option value="">— за замовчуванням ({{ $this->order?->delivery_time ?? 'з замовлення' }}) —</option>
                        <optgroup label="🌅 Ранок">
                            @foreach($morningSlots as $slot)
                                <option value="{{ $slot }}" @selected($modalDeliveryTime === $slot)>{{ $slot }}</option>
                            @endforeach
                        </optgroup>
                        <optgroup label="🌆 Вечір">
                            @foreach($eveningSlots as $slot)
                                <option value="{{ $slot }}" @selected($modalDeliveryTime === $slot)>{{ $slot }}</option>
                            @endforeach
                        </optgroup>
                    </select>
                    @if($modalDeliveryTime)
                        <p class="text-xs text-orange-500 mt-1">⚠️ Перевизначено для цього дня</p>
                    @endif
                </div>

                {{-- Override дати доставки --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">🚚 Перенести доставку на іншу дату</label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mb-2">
                        Залиш порожнім для автоматичного режиму (вихідні з налаштувань логістики враховуються самі).
                    </p>
                    <input type="date" wire:model="modalDeliveryDateOverride"
                        class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
                    @if($modalDeliveryDateOverride)
                        <p class="text-xs text-orange-500 mt-1">⚠️ Доставку перенесено на {{ \Carbon\Carbon::parse($modalDeliveryDateOverride)->format('d.m.Y') }}</p>
                    @endif
                </div>

                {{-- Доплата за дальню доставку --}}
                @php
                    $farFeeValue = (float) (\App\Models\Setting::where('key', 'far_delivery_fee')->value('value') ?: 0);
                @endphp
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <label class="flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300 cursor-pointer">
                        <input type="checkbox" wire:model="modalIsFarDelivery"
                            class="rounded border-gray-300 dark:border-gray-600 text-primary-600 focus:ring-primary-500" />
                        <span>📍 Дальня доставка (+{{ rtrim(rtrim(number_format($farFeeValue, 2, '.', ''), '0'), '.') }} ₴)</span>
                    </label>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-1 ml-6">
                        Додаткова плата за далекий заїзд — включається у вартість для клієнта і нараховується кур'єру.
                    </p>
                </div>

                {{-- Знижка на день --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Знижка на цей день</label>
                    <div class="flex gap-3">
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 mb-1">Тип</label>
                            <select wire:model.live="modalDiscountType"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500">
                                <option value="">— без знижки —</option>
                                <option value="percent">Відсоткова (%)</option>
                                <option value="fixed">Фіксована (₴)</option>
                            </select>
                        </div>
                        @if($modalDiscountType)
                        <div class="flex-1">
                            <label class="block text-xs text-gray-500 mb-1">
                                {{ $modalDiscountType === 'percent' ? 'Розмір (%)' : 'Сума (₴)' }}
                            </label>
                            <input type="number" wire:model="modalDiscountValue" min="0"
                                {{ $modalDiscountType === 'percent' ? 'max="100"' : '' }}
                                placeholder="{{ $modalDiscountType === 'percent' ? '10' : '50' }}"
                                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 px-3 py-2 text-sm focus:ring-2 focus:ring-primary-500" />
                        </div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <button type="button" wire:click="removeDay"
                    wire:confirm="Видалити цей день з замовлення?"
                    class="px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 rounded-lg transition-colors flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    Видалити день
                </button>
                <button type="button" wire:click="saveAddress"
                    class="px-4 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors">
                    Зберегти
                </button>
            </div>
        </div>
    </div>
    @endif

</div>
