<x-filament-panels::page>

@php
    $dateFormatted = \Carbon\Carbon::parse($planDate)->locale('uk')->isoFormat('dddd, D MMMM YYYY');
    $dateShort     = \Carbon\Carbon::parse($planDate)->format('d.m.Y');

    // Кольори для П1–П6+
    $brigadeColors = [
        0 => ['bg' => 'bg-blue-500',   'light' => 'bg-blue-50 dark:bg-blue-900/20',   'border' => 'border-blue-200 dark:border-blue-800',   'text' => 'text-blue-700 dark:text-blue-300',   'badge' => 'bg-blue-500 text-white',   'dot' => 'bg-blue-500'],
        1 => ['bg' => 'bg-orange-500', 'light' => 'bg-orange-50 dark:bg-orange-900/20','border' => 'border-orange-200 dark:border-orange-800','text' => 'text-orange-700 dark:text-orange-300','badge' => 'bg-orange-500 text-white', 'dot' => 'bg-orange-500'],
        2 => ['bg' => 'bg-emerald-500','light' => 'bg-emerald-50 dark:bg-emerald-900/20','border' => 'border-emerald-200 dark:border-emerald-800','text' => 'text-emerald-700 dark:text-emerald-300','badge' => 'bg-emerald-500 text-white','dot' => 'bg-emerald-500'],
        3 => ['bg' => 'bg-rose-500',   'light' => 'bg-rose-50 dark:bg-rose-900/20',   'border' => 'border-rose-200 dark:border-rose-800',   'text' => 'text-rose-700 dark:text-rose-300',   'badge' => 'bg-rose-500 text-white',   'dot' => 'bg-rose-500'],
        4 => ['bg' => 'bg-violet-500', 'light' => 'bg-violet-50 dark:bg-violet-900/20','border' => 'border-violet-200 dark:border-violet-800','text' => 'text-violet-700 dark:text-violet-300','badge' => 'bg-violet-500 text-white', 'dot' => 'bg-violet-500'],
        5 => ['bg' => 'bg-amber-500',  'light' => 'bg-amber-50 dark:bg-amber-900/20', 'border' => 'border-amber-200 dark:border-amber-800', 'text' => 'text-amber-700 dark:text-amber-300',  'badge' => 'bg-amber-500 text-white',  'dot' => 'bg-amber-500'],
    ];

    // Якщо план є — будуємо карту code→кольори
    $codeColorMap = [];
    if ($plan && !empty($plan['brigade'])) {
        foreach ($plan['brigade'] as $i => $member) {
            $codeColorMap[$member['code']] = $brigadeColors[$i % count($brigadeColors)];
        }
    }

    $priorityConfig = [
        'high'   => ['bg' => 'bg-red-50 dark:bg-red-900/20',    'border' => 'border-red-300 dark:border-red-700',    'text' => 'text-red-700 dark:text-red-300',    'icon' => '🔴', 'label' => 'Критично'],
        'medium' => ['bg' => 'bg-amber-50 dark:bg-amber-900/20','border' => 'border-amber-300 dark:border-amber-700','text' => 'text-amber-700 dark:text-amber-300', 'icon' => '🟡', 'label' => 'Увага'],
        'low'    => ['bg' => 'bg-gray-50 dark:bg-gray-900/20',  'border' => 'border-gray-300 dark:border-gray-700',  'text' => 'text-gray-600 dark:text-gray-400',   'icon' => '🟢', 'label' => 'Інфо'],
    ];
@endphp

<div class="space-y-6">

    {{-- ===== ЯКЩО ПЛАН ЩЕ НЕ ЗГЕНЕРОВАНО ===== --}}
    @if(!$plan)

        {{-- Заголовок --}}
        <div class="p-5 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm">
            <div class="flex items-center gap-3 mb-1">
                <div class="p-2 bg-primary-50 dark:bg-primary-900/20 rounded-xl">
                    <x-heroicon-o-sparkles class="w-6 h-6 text-primary-500" />
                </div>
                <div>
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Генерація плану кухні</h2>
                    <p class="text-sm text-gray-500">На {{ $dateShort }} (завтра)</p>
                </div>
            </div>
        </div>

        {{-- Вибір бригади --}}
        <div class="bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
            <div class="p-4 border-b dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100 flex items-center gap-2">
                    <x-heroicon-o-user-group class="w-5 h-5 text-gray-400" />
                    Бригада на завтра
                    <span class="text-xs font-normal text-gray-500 ml-1">— відмітьте хто виходить</span>
                </h3>
            </div>

            <div class="p-4">
                @if($this->activeEmployees->isEmpty())
                    <p class="text-sm text-gray-500 italic">Немає активних співробітників. Додайте їх у розділі "Співробітники".</p>
                @else
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach($this->activeEmployees as $emp)
                            @php
                                $isChecked = in_array((string)$emp['id'], $selectedEmployees);
                                $posIcon = match($emp['position']) {
                                    'cook'    => '👨‍🍳',
                                    'packer'  => '📦',
                                    'courier' => '🚴',
                                    'manager' => '📋',
                                    default   => '👤',
                                };
                            @endphp
                            <label
                                class="flex items-center gap-3 p-3 rounded-xl border-2 cursor-pointer transition-all
                                    {{ $isChecked
                                        ? 'border-primary-400 bg-primary-50 dark:bg-primary-900/20 dark:border-primary-600'
                                        : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}"
                                wire:click="$set('selectedEmployees', {{ json_encode(
                                    $isChecked
                                        ? array_values(array_filter($selectedEmployees, fn($id) => $id !== (string)$emp['id']))
                                        : array_merge($selectedEmployees, [(string)$emp['id']])
                                ) }})"
                            >
                                <span class="text-xl">{{ $posIcon }}</span>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-sm text-gray-900 dark:text-white truncate">{{ $emp['name'] }}</p>
                                    <p class="text-xs text-gray-500">{{ $emp['label'] }}</p>
                                </div>
                                <div class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0
                                    {{ $isChecked ? 'border-primary-500 bg-primary-500' : 'border-gray-300 dark:border-gray-600' }}">
                                    @if($isChecked)
                                        <x-heroicon-s-check class="w-3 h-3 text-white" />
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Кнопка генерації --}}
            <div class="p-4 border-t dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                <div class="flex items-center justify-between gap-4 flex-wrap">
                    <p class="text-sm text-gray-500">
                        Обрано: <strong class="text-gray-900 dark:text-white">{{ count($selectedEmployees) }}</strong> чол.
                        &nbsp;·&nbsp; Вартість запиту ~$0.005
                    </p>

                    <button
                        wire:click="generate"
                        wire:loading.attr="disabled"
                        wire:loading.class="opacity-60 cursor-not-allowed"
                        class="inline-flex items-center gap-2 px-6 py-2.5 bg-primary-600 hover:bg-primary-700 text-white font-semibold rounded-xl transition-all shadow-sm disabled:opacity-60 disabled:cursor-not-allowed"
                        {{ empty($selectedEmployees) ? 'disabled' : '' }}
                    >
                        <span wire:loading.remove wire:target="generate">
                            <x-heroicon-o-sparkles class="w-5 h-5 inline -mt-0.5" />
                        </span>
                        <span wire:loading wire:target="generate">
                            <svg class="w-5 h-5 animate-spin inline" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                        </span>
                        <span wire:loading.remove wire:target="generate">Згенерувати план дня</span>
                        <span wire:loading wire:target="generate">Генерую план...</span>
                    </button>
                </div>

                <p class="mt-2 text-xs text-gray-400">
                    * Кнопка буде заблокована після генерації. Для повторної генерації — зверніться до адміністратора.
                </p>
            </div>
        </div>

    @else
    {{-- ===== ПЛАН ГОТОВИЙ — ВІДОБРАЖЕННЯ ===== --}}

        {{-- Верхня панель --}}
        <div class="p-4 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase font-bold tracking-widest text-gray-400 mb-1">План кухні</p>
                    <h2 class="text-xl font-black text-gray-900 dark:text-white capitalize">{{ $dateFormatted }}</h2>
                    @if(!empty($plan['brigade_recommendation']))
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $plan['brigade_recommendation'] }}</p>
                    @endif
                </div>
                <div class="flex items-center gap-3 flex-wrap">
                    {{-- Бейджі бригади --}}
                    @if(!empty($plan['brigade']))
                        @foreach($plan['brigade'] as $i => $member)
                            @php $c = $brigadeColors[$i % count($brigadeColors)]; @endphp
                            <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full {{ $c['light'] }} {{ $c['border'] }} border">
                                <span class="w-2 h-2 rounded-full {{ $c['dot'] }}"></span>
                                <span class="text-xs font-semibold {{ $c['text'] }}">{{ $member['code'] }}</span>
                                <span class="text-xs text-gray-600 dark:text-gray-400">{{ $member['person'] }}</span>
                            </div>
                        @endforeach
                    @endif
                    <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-gray-100 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                        <x-heroicon-o-clock class="w-4 h-4 text-gray-400" />
                        <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">08:00 — 16:00</span>
                    </div>
                </div>
            </div>

            @if(!empty($plan['general_advice']))
                <div class="mt-3 p-3 bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800 rounded-xl">
                    <p class="text-sm text-primary-800 dark:text-primary-200">
                        <span class="font-semibold">💡 Порада шефа:</span> {{ $plan['general_advice'] }}
                    </p>
                </div>
            @endif
        </div>

        {{-- ===== КРИТИЧНІ ЗАМІНИ (завжди видно зверху) ===== --}}
        @if(!empty($plan['critical_replacements']))
            <div class="bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
                <div class="p-4 border-b dark:border-gray-800 bg-red-50 dark:bg-red-900/10">
                    <h3 class="font-bold text-red-700 dark:text-red-400 flex items-center gap-2">
                        <x-heroicon-o-exclamation-triangle class="w-5 h-5" />
                        Індивідуальні заміни та особливі клієнти
                    </h3>
                </div>
                <div class="p-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    @foreach($plan['critical_replacements'] as $rep)
                        @php
                            $priority = $rep['priority'] ?? 'medium';
                            $pc = $priorityConfig[$priority] ?? $priorityConfig['medium'];
                        @endphp
                        <div class="p-3 rounded-xl border {{ $pc['bg'] }} {{ $pc['border'] }}">
                            <div class="flex items-start gap-2">
                                <span class="text-lg leading-none mt-0.5">{{ $pc['icon'] }}</span>
                                <div>
                                    <p class="font-semibold text-sm text-gray-900 dark:text-white">{{ $rep['client'] }}</p>
                                    <p class="text-xs {{ $pc['text'] }} mt-0.5">{{ $rep['note'] }}</p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ===== КАРТКИ БРИГАДИ ===== --}}
        @if(!empty($plan['brigade']))
            <div>
                <h3 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3">Склад бригади та задачі</h3>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
                    @foreach($plan['brigade'] as $i => $member)
                        @php $c = $brigadeColors[$i % count($brigadeColors)]; @endphp
                        <div class="bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden flex flex-col">

                            {{-- Заголовок картки --}}
                            <div class="p-4 {{ $c['light'] }} {{ $c['border'] }} border-b">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $c['badge'] }}">
                                                {{ $member['code'] }}
                                            </span>
                                            <span class="text-xs font-semibold {{ $c['text'] }}">{{ $member['role'] }}</span>
                                        </div>
                                        <p class="font-bold text-gray-900 dark:text-white">{{ $member['person'] }}</p>
                                        @if(!empty($member['meal_focus']))
                                            <p class="text-xs text-gray-500 mt-0.5">{{ $member['meal_focus'] }}</p>
                                        @endif
                                    </div>
                                    <div class="w-10 h-10 rounded-full {{ $c['bg'] }} flex items-center justify-center flex-shrink-0">
                                        <span class="text-white font-black text-sm">{{ $member['code'] }}</span>
                                    </div>
                                </div>
                            </div>

                            {{-- Список задач --}}
                            <div class="p-4 flex-1">
                                @if(!empty($member['tasks']))
                                    <p class="text-[10px] uppercase font-bold tracking-widest text-gray-400 mb-2">Задачі</p>
                                    <ul class="space-y-1.5">
                                        @foreach($member['tasks'] as $task)
                                            <li class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                <span class="w-1.5 h-1.5 rounded-full {{ $c['dot'] }} mt-1.5 flex-shrink-0"></span>
                                                {{ $task }}
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                {{-- Заміни що стосуються цього кухаря --}}
                                @if(!empty($member['replacements_to_handle']))
                                    <div class="mt-3 pt-3 border-t dark:border-gray-800">
                                        <p class="text-[10px] uppercase font-bold tracking-widest text-amber-500 mb-2">⚠ Заміни</p>
                                        <ul class="space-y-1">
                                            @foreach($member['replacements_to_handle'] as $rep)
                                                <li class="text-xs text-amber-700 dark:text-amber-300 bg-amber-50 dark:bg-amber-900/20 rounded-lg px-2 py-1">
                                                    {{ $rep }}
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ===== ТАЙМЛАЙН ===== --}}
        @if(!empty($plan['timeline']))
            <div>
                <h3 class="text-sm font-bold uppercase tracking-widest text-gray-400 mb-3">Таймінг дня</h3>
                <div class="bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-2xl shadow-sm overflow-hidden">
                    <div class="divide-y dark:divide-gray-800">
                        @foreach($plan['timeline'] as $slot)
                            <div class="flex gap-0">

                                {{-- Час --}}
                                <div class="w-20 flex-shrink-0 p-4 flex flex-col items-center border-r dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50">
                                    <span class="text-sm font-black text-gray-900 dark:text-white">{{ $slot['time'] }}</span>
                                    <div class="w-0.5 flex-1 bg-gray-200 dark:bg-gray-700 mt-2 rounded-full min-h-[20px]"></div>
                                </div>

                                {{-- Задачі цього слоту --}}
                                <div class="flex-1 p-4 space-y-2">
                                    @if(!empty($slot['tasks']))
                                        @foreach($slot['tasks'] as $task)
                                            @php
                                                $performer = $task['performer'] ?? '';
                                                $tc = $codeColorMap[$performer] ?? ['badge' => 'bg-gray-500 text-white', 'light' => 'bg-gray-50 dark:bg-gray-800', 'border' => 'border-gray-200 dark:border-gray-700', 'text' => 'text-gray-600 dark:text-gray-400'];
                                            @endphp
                                            <div class="flex items-start gap-3">
                                                @if($performer)
                                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold flex-shrink-0 mt-0.5 {{ $tc['badge'] }}">
                                                        {{ $performer }}
                                                    </span>
                                                @endif
                                                <div class="flex-1">
                                                    <p class="text-sm text-gray-800 dark:text-gray-200">{{ $task['action'] }}</p>
                                                    @if(!empty($task['note']))
                                                        <p class="text-xs text-amber-600 dark:text-amber-400 mt-0.5 flex items-center gap-1">
                                                            <span>⚠</span> {{ $task['note'] }}
                                                        </p>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- Футер --}}
        @php
            $record = \App\Models\KitchenDailyPlan::where('date', $planDate)->first();
        @endphp
        @if($record)
            <p class="text-center text-xs text-gray-400">
                Згенеровано {{ $record->created_at->format('d.m.Y о H:i') }}
                @if($record->generated_by) · {{ $record->generated_by }} @endif
            </p>
        @endif

    @endif

</div>
</x-filament-panels::page>
