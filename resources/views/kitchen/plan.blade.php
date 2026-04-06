<!DOCTYPE html>
<html lang="uk" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>План кухні на {{ $targetDateFormatted }} | Avocado Food</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                extend: {
                    colors: {
                        zinc: { 850: '#202024', 900: '#18181b', 950: '#09090b' },
                        avocado: { 400: '#fbbf24', 500: '#f59e0b', 600: '#d97706' }
                    }
                }
            }
        }
    </script>
    <style>
        .gradient-border { background: linear-gradient(#18181b, #18181b) padding-box, linear-gradient(135deg, var(--c1), var(--c2)) border-box; border: 1.5px solid transparent; }
        .timeline-line::before { content: ''; position: absolute; left: 19px; top: 28px; bottom: -8px; width: 2px; background: linear-gradient(to bottom, #f59e0b40, transparent); }
    </style>
</head>
<body class="bg-zinc-950 text-zinc-100 min-h-screen font-sans">

<div class="max-w-5xl mx-auto px-4 py-8">

    {{-- HEADER --}}
    <div class="mb-8">
        <a href="/admin" class="inline-flex items-center gap-1.5 text-zinc-500 hover:text-zinc-300 text-sm mb-4 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            Повернутись в CRM
        </a>
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-extrabold text-white tracking-tight">План кухні</h1>
                <p class="text-zinc-400 mt-1 text-base">Виробничий день: <span class="text-avocado-500 font-bold">{{ $targetDateFormatted }}</span></p>
            </div>
            @if($existingPlan)
            <div class="text-right hidden sm:block">
                <div class="text-xs text-zinc-600">Згенеровано</div>
                <div class="text-sm text-zinc-400 font-medium">{{ $existingPlan->created_at->format('d.m.Y о H:i') }}</div>
            </div>
            @endif
        </div>
    </div>

    @if(session('error'))
    <div class="bg-red-900/30 border border-red-700/50 rounded-2xl px-5 py-4 mb-6 text-red-300 text-sm">❌ {{ session('error') }}</div>
    @endif

    @if(!$existingPlan)
    {{-- ====== ФОРМА ГЕНЕРАЦІЇ ====== --}}
    <div class="bg-zinc-900/80 backdrop-blur rounded-2xl border border-zinc-800 p-7">
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-avocado-500/20 flex items-center justify-center text-xl">🤖</div>
            <div>
                <h2 class="text-lg font-bold text-white">Бригада на завтра</h2>
                <p class="text-zinc-500 text-sm">Відміть хто виходить — ШІ розподілить задачі на основі меню</p>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-7" id="employeeList">
            @foreach($employees as $employee)
            <label class="relative flex items-center gap-3 bg-zinc-800/60 border border-zinc-700 hover:border-avocado-500/60 rounded-xl px-4 py-3.5 cursor-pointer transition-all has-[:checked]:border-avocado-500 has-[:checked]:bg-avocado-500/10">
                <input type="checkbox" name="employee_ids[]" value="{{ $employee->id }}" class="w-4 h-4 accent-amber-500 rounded flex-shrink-0">
                <div class="min-w-0">
                    <div class="text-sm font-semibold text-zinc-100 truncate">{{ $employee->name }}</div>
                    <div class="text-xs text-zinc-500">{{ $employee->position }}</div>
                </div>
            </label>
            @endforeach
        </div>

        <div class="flex items-center gap-4 flex-wrap">
            <button id="generateBtn" onclick="startGenerate()"
                    class="inline-flex items-center gap-2 bg-avocado-500 hover:bg-avocado-600 active:scale-95 text-black font-bold px-7 py-3 rounded-xl transition-all shadow-lg shadow-avocado-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Згенерувати план дня
            </button>
            <p class="text-zinc-600 text-sm">Доступно один раз на день</p>
        </div>

        <div id="generatingStatus" class="hidden mt-6 bg-zinc-800/60 border border-zinc-700 rounded-xl px-5 py-4">
            <div class="flex items-center gap-3 text-avocado-400">
                <svg class="animate-spin h-5 w-5 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                </svg>
                <span id="statusText" class="font-medium text-sm">Відправляю дані до ШІ...</span>
            </div>
            <div class="mt-3 h-1 bg-zinc-700 rounded-full overflow-hidden">
                <div id="progressBar" class="h-full bg-avocado-500 rounded-full transition-all duration-1000" style="width:5%"></div>
            </div>
            <p class="text-zinc-600 text-xs mt-2">Зазвичай займає 30–90 секунд. Не закривайте сторінку.</p>
        </div>
    </div>

    @else
    {{-- ====== ПЛАН ІСНУЄ ====== --}}
    @php
        $plan = $existingPlan->plan_json;
        $summary = $plan['summary'] ?? [];
        $brigade = $plan['brigade'] ?? [];
        $timeline = $plan['timeline'] ?? [];
        $criticalClients = $plan['critical_clients'] ?? [];

        $palette = [
            ['bg'=>'bg-blue-500/10',   'border'=>'border-blue-500/40',   'text'=>'text-blue-400',   'badge'=>'bg-blue-500',   'dot'=>'bg-blue-400',   'c1'=>'#3b82f6','c2'=>'#60a5fa'],
            ['bg'=>'bg-orange-500/10', 'border'=>'border-orange-500/40', 'text'=>'text-orange-400', 'badge'=>'bg-orange-500', 'dot'=>'bg-orange-400', 'c1'=>'#f97316','c2'=>'#fb923c'],
            ['bg'=>'bg-green-500/10',  'border'=>'border-green-500/40',  'text'=>'text-green-400',  'badge'=>'bg-green-500',  'dot'=>'bg-green-400',  'c1'=>'#22c55e','c2'=>'#4ade80'],
            ['bg'=>'bg-purple-500/10', 'border'=>'border-purple-500/40', 'text'=>'text-purple-400', 'badge'=>'bg-purple-500', 'dot'=>'bg-purple-400', 'c1'=>'#a855f7','c2'=>'#c084fc'],
            ['bg'=>'bg-pink-500/10',   'border'=>'border-pink-500/40',   'text'=>'text-pink-400',   'badge'=>'bg-pink-500',   'dot'=>'bg-pink-400',   'c1'=>'#ec4899','c2'=>'#f472b6'],
            ['bg'=>'bg-teal-500/10',   'border'=>'border-teal-500/40',   'text'=>'text-teal-400',   'badge'=>'bg-teal-500',   'dot'=>'bg-teal-400',   'c1'=>'#14b8a6','c2'=>'#2dd4bf'],
            ['bg'=>'bg-red-500/10',    'border'=>'border-red-500/40',    'text'=>'text-red-400',    'badge'=>'bg-red-500',    'dot'=>'bg-red-400',    'c1'=>'#ef4444','c2'=>'#f87171'],
            ['bg'=>'bg-yellow-500/10', 'border'=>'border-yellow-500/40', 'text'=>'text-yellow-400', 'badge'=>'bg-yellow-500', 'dot'=>'bg-yellow-400', 'c1'=>'#eab308','c2'=>'#facc15'],
        ];
        $codeColor = [];
        foreach ($brigade as $i => $m) {
            $codeColor[$m['code']] = $palette[$i % count($palette)];
        }
    @endphp

    {{-- STATS BAR --}}
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-7">
<div class="bg-zinc-900 border border-zinc-800 rounded-2xl px-4 py-4 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-blue-500/15 flex items-center justify-center text-base">⏰</div>
            <div>
                <div class="text-xs text-zinc-500">Зміна</div>
                <div class="text-lg font-bold text-white">08:00 <span class="text-sm font-normal text-zinc-400">– 16:00</span></div>
            </div>
        </div>
        @if(!empty($summary['optimal_brigade_size']))
        <div class="bg-zinc-900 border border-zinc-800 rounded-2xl px-4 py-4 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-green-500/15 flex items-center justify-center text-base">✅</div>
            <div>
                <div class="text-xs text-zinc-500">Оптимально</div>
                <div class="text-lg font-bold text-white">{{ $summary['optimal_brigade_size'] }} <span class="text-sm font-normal text-zinc-400">кухарі</span></div>
            </div>
        </div>
        @endif
        @if(count($allReplacements) > 0)
        <div class="bg-red-950/40 border border-red-800/50 rounded-2xl px-4 py-4 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-red-500/20 flex items-center justify-center text-base">⚠️</div>
            <div>
                <div class="text-xs text-red-400">Із замінами</div>
                <div class="text-lg font-bold text-white">{{ count($allReplacements) }} <span class="text-sm font-normal text-zinc-400">клієнти</span></div>
            </div>
        </div>
        @endif
    </div>

    {{-- SUMMARY: ПОЧАТИ + ВУЗЬКІ МІСЦЯ --}}
    @if(!empty($summary['start_immediately']) || !empty($summary['bottlenecks']))
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        @if(!empty($summary['start_immediately']))
        <div class="bg-zinc-900 border border-avocado-500/30 rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-3">
                <span class="w-6 h-6 rounded-lg bg-avocado-500/20 flex items-center justify-center text-sm">🚀</span>
                <span class="text-xs font-semibold text-avocado-400 uppercase tracking-wider">Почати рівно о 08:00</span>
            </div>
            <ul class="space-y-2">
                @foreach($summary['start_immediately'] as $item)
                <li class="flex items-start gap-2 text-sm text-zinc-200">
                    <span class="text-avocado-500 mt-0.5 flex-shrink-0">→</span>{{ $item }}
                </li>
                @endforeach
            </ul>
        </div>
        @endif
        @if(!empty($summary['bottlenecks']))
        <div class="bg-amber-950/30 border border-amber-700/40 rounded-2xl p-5">
            <div class="flex items-center gap-2 mb-3">
                <span class="w-6 h-6 rounded-lg bg-amber-500/20 flex items-center justify-center text-sm">⚡</span>
                <span class="text-xs font-semibold text-amber-400 uppercase tracking-wider">Вузькі місця</span>
            </div>
            <ul class="space-y-2">
                @foreach($summary['bottlenecks'] as $item)
                <li class="flex items-start gap-2 text-sm text-amber-200">
                    <span class="flex-shrink-0 mt-0.5">•</span>{{ $item }}
                </li>
                @endforeach
            </ul>
        </div>
        @endif
    </div>
    @endif

    {{-- ЗАМІНИ З БД (всі, без GPT) --}}
    @if(!empty($allReplacements))
    <div class="bg-red-950/30 border border-red-700/50 rounded-2xl p-5 mb-7">
        <div class="flex items-center gap-2 mb-4">
            <span class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-red-500"></span>
            </span>
            <span class="text-xs font-bold text-red-400 uppercase tracking-wider">Індивідуальні заміни — обов'язкова перевірка</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            @foreach($allReplacements as $cr)
            <div class="rounded-xl border border-red-500/60 bg-red-950/40 px-4 py-3.5">
                <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm">🔴</span>
                    <span class="font-bold text-white text-sm">{{ $cr['client'] }}</span>
                    <span class="ml-auto text-xs px-2 py-0.5 rounded-full bg-red-900/60 text-red-300">
                        {{ count($cr['items']) }} {{ count($cr['items']) === 1 ? 'заміна' : 'заміни' }}
                    </span>
                </div>
                <ul class="space-y-1">
                    @foreach($cr['items'] as $item)
                    @php
                        $icon = match($item['type']) {
                            'force'     => '⚡',
                            'dish'      => '🔄',
                            'exclusion' => '❌',
                            default     => '🔄',
                        };
                    @endphp
                    <li class="text-xs text-zinc-300 flex items-start gap-1.5">
                        <span class="flex-shrink-0 mt-0.5">{{ $icon }}</span>
                        {{ $item['text'] }}
                    </li>
                    @endforeach
                </ul>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- БРИГАДА --}}
    @if(!empty($brigade))
    <div class="flex items-center gap-3 mb-4">
        <h2 class="text-lg font-bold text-white">Склад бригади</h2>
        <div class="flex-1 h-px bg-zinc-800"></div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
        @foreach($brigade as $member)
        @php $clr = $codeColor[$member['code']] ?? $palette[0]; @endphp
        <div class="rounded-2xl border {{ $clr['border'] }} bg-zinc-900 overflow-hidden">
            {{-- Card Header --}}
            <div class="{{ $clr['bg'] }} px-5 py-4 border-b {{ $clr['border'] }}">
                <div class="flex items-center gap-3">
                    <span class="text-xs font-extrabold px-3 py-1.5 rounded-lg {{ $clr['badge'] }} text-white tracking-wide">{{ $member['code'] }}</span>
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-white text-sm">{{ $member['person'] ?? '—' }}</div>
                        <div class="{{ $clr['text'] }} text-xs font-medium">{{ $member['role'] ?? '' }}</div>
                    </div>
                </div>
            </div>
            {{-- Tasks --}}
            <div class="px-5 py-4">
                @if(!empty($member['tasks']))
                <ul class="space-y-2">
                    @foreach($member['tasks'] as $task)
                    <li class="flex items-start gap-2.5 text-sm text-zinc-300 group">
                        <span class="{{ $clr['dot'] }} w-1.5 h-1.5 rounded-full mt-1.5 flex-shrink-0 opacity-70"></span>
                        <span class="leading-relaxed">{{ $task }}</span>
                    </li>
                    @endforeach
                </ul>
                @endif
                @if(!empty($member['replacements']))
                <div class="mt-4 pt-4 border-t border-zinc-800">
                    <div class="flex items-center gap-1.5 mb-2">
                        <span class="text-xs">⚠️</span>
                        <span class="text-xs font-semibold text-yellow-500 uppercase tracking-wider">Заміни</span>
                    </div>
                    <ul class="space-y-1.5">
                        @foreach($member['replacements'] as $rep)
                        <li class="flex items-start gap-2 text-xs text-yellow-300">
                            <span class="text-yellow-600 flex-shrink-0 mt-0.5">→</span>{{ $rep }}
                        </li>
                        @endforeach
                    </ul>
                </div>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ТАЙМЛАЙН --}}
    @if(!empty($timeline))
    <div class="flex items-center gap-3 mb-5">
        <h2 class="text-lg font-bold text-white">Таймінг дня</h2>
        <div class="flex-1 h-px bg-zinc-800"></div>
        <span class="text-xs text-zinc-600">08:00 → 15:30</span>
    </div>
    <div class="space-y-1">
        @foreach($timeline as $slot)
        <div class="relative flex gap-5 pb-6 timeline-line">
            {{-- Час --}}
            <div class="flex-shrink-0 pt-0.5">
                <div class="w-10 h-10 rounded-xl bg-avocado-500/15 border border-avocado-500/30 flex items-center justify-center">
                    <span class="text-xs font-bold text-avocado-400 leading-tight text-center">{{ $slot['time'] }}</span>
                </div>
            </div>

            {{-- Події --}}
            <div class="flex-1 space-y-2 pt-1">
                @foreach($slot['events'] ?? [] as $task)
                @php $tc = $codeColor[$task['who'] ?? ''] ?? $palette[0]; @endphp
                <div class="rounded-xl border {{ $tc['border'] }} {{ $tc['bg'] }} px-4 py-3 flex items-start gap-3">
                    <span class="text-xs font-extrabold px-2.5 py-1 rounded-lg {{ $tc['badge'] }} text-white flex-shrink-0 mt-0.5 whitespace-nowrap">
                        {{ $task['who'] ?? '' }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="text-sm text-zinc-100 font-medium leading-relaxed">{{ $task['what'] ?? '' }}</div>
                        @if(!empty($task['replacements']))
                            @foreach($task['replacements'] as $rep)
                            @if($rep && $rep !== 'null' && !is_null($rep))
                            <div class="inline-flex items-center gap-1 mt-1.5 text-xs bg-yellow-950/50 border border-yellow-700/40 text-yellow-300 rounded-lg px-2.5 py-1">
                                <span>⚠️</span>{{ $rep }}
                            </div>
                            @endif
                            @endforeach
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>
    @endif

    @endif {{-- end existingPlan --}}

</div>

<script>
function startGenerate() {
    const checked = document.querySelectorAll('input[name="employee_ids[]"]:checked');
    if (checked.length === 0) { alert('Відміть хоча б одного співробітника'); return; }

    const btn = document.getElementById('generateBtn');
    btn.disabled = true;
    btn.classList.add('opacity-50', 'cursor-not-allowed');
    document.getElementById('generatingStatus').classList.remove('hidden');

    const ids = Array.from(checked).map(c => c.value);
    const formData = new FormData();
    ids.forEach(id => formData.append('employee_ids[]', id));
    formData.append('_token', '{{ csrf_token() }}');

    fetch('{{ route("kitchen.plan.generate") }}', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'exists' || data.status === 'done') { window.location.reload(); return; }
            if (data.status === 'started') { cycleMessages(); pollStatus(); }
        })
        .catch(() => { document.getElementById('statusText').textContent = 'Помилка з\'єднання. Оновіть сторінку.'; });
}

const progressMessages = [
    '📋 Читаю виробничий план на завтра...',
    '🔄 Аналізую індивідуальні заміни клієнтів...',
    '💬 Перевіряю коментарі до замовлень...',
    '🧠 ШІ розподіляє задачі між бригадою...',
    '⏱️ Будую таймінг дня по годинах...',
    '⚠️ Перевіряю критичні заміни...',
    '✍️ Формую фінальний план...',
    '🔍 Перевіряю логіку приготування...',
    '📦 Майже готово, завершую...',
];
const progressWidths = [10, 20, 32, 45, 58, 70, 80, 90, 97];
let msgIndex = 0;

function cycleMessages() {
    if (msgIndex < progressMessages.length) {
        document.getElementById('statusText').textContent = progressMessages[msgIndex];
        document.getElementById('progressBar').style.width = progressWidths[msgIndex] + '%';
        msgIndex++;
        setTimeout(cycleMessages, 8000);
    }
}

function pollStatus() {
    setTimeout(() => {
        fetch('{{ route("kitchen.plan.status") }}')
            .then(r => r.json())
            .then(data => {
                if (data.status === 'done') {
                    document.getElementById('statusText').textContent = '✅ Готово! Завантажую план...';
                    document.getElementById('progressBar').style.width = '100%';
                    setTimeout(() => window.location.reload(), 500);
                } else if (data.status === 'error') {
                    document.getElementById('statusText').textContent = '❌ Помилка: ' + (data.message || 'невідома помилка');
                    document.getElementById('generateBtn').disabled = false;
                    document.getElementById('generateBtn').classList.remove('opacity-50', 'cursor-not-allowed');
                } else { pollStatus(); }
            });
    }, 4000);
}
</script>

</body>
</html>
