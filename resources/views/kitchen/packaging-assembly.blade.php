<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список пакування — {{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'avocado': { 400: '#a3e635', 500: '#84cc16' },
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-zinc-950 text-white min-h-screen">

<div class="max-w-6xl mx-auto px-4 py-6">

    {{-- ШАПКА --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-2xl font-black text-white">📦 Список пакування</h1>
            <p class="text-zinc-400 text-sm mt-1">На дату: <span class="text-white font-semibold">{{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</span></p>
        </div>
        <div class="flex items-center gap-3">
            <form method="GET" action="{{ route('packaging.assembly') }}" class="flex items-center gap-2">
                <input type="date" name="date" value="{{ $date }}"
                    class="bg-zinc-800 border border-white/10 text-white rounded-lg px-3 py-2 text-sm">
                <button type="submit" class="bg-avocado-500 hover:bg-avocado-400 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                    Оновити
                </button>
            </form>
            <button onclick="window.print()" class="bg-zinc-700 hover:bg-zinc-600 text-white px-4 py-2 rounded-lg text-sm font-semibold transition">
                🖨 Друк
            </button>
        </div>
    </div>

    @if(!$menu)
        <div class="bg-zinc-900 border border-white/10 rounded-2xl p-8 text-center text-zinc-500">
            Меню на цю дату не знайдено
        </div>
    @elseif(empty($perClient))
        <div class="bg-zinc-900 border border-white/10 rounded-2xl p-8 text-center text-zinc-500">
            Немає замовлень на {{ \Carbon\Carbon::parse($date)->format('d.m.Y') }},<br>
            або не проставлені типи упаковки в стравах та упаковці
        </div>
    @else

    {{-- ЗВЕДЕНА ТАБЛИЦЯ --}}
    <div class="bg-zinc-900 border border-white/5 rounded-2xl overflow-hidden mb-6">
        <div class="px-5 py-4 border-b border-white/5 flex items-center justify-between">
            <div>
                <h2 class="text-white font-bold text-lg">Загальна потреба на день</h2>
                <p class="text-zinc-500 text-sm">Скільки кожної позиції підготувати</p>
            </div>
            <div class="text-right">
                <p class="text-zinc-500 text-xs uppercase tracking-wider">Загальна вартість пакування</p>
                <p class="text-emerald-400 font-black text-2xl">{{ number_format($totalCost, 2, '.', ' ') }} ₴</p>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-zinc-800/50 text-zinc-400 text-xs uppercase tracking-wider">
                        <th class="text-left px-5 py-3">Позиція упаковки</th>
                        <th class="text-center px-4 py-3">Тип</th>
                        <th class="text-center px-4 py-3">Кількість</th>
                        <th class="text-right px-5 py-3">Ціна × шт</th>
                        <th class="text-right px-5 py-3">Сума</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-white/5">
                    @foreach($summary as $id => $item)
                    @php
                        $typeColors = [
                            'бокс'     => 'bg-emerald-500/20 text-emerald-400',
                            'кришка'   => 'bg-zinc-500/20 text-zinc-400',
                            'пляшка'   => 'bg-blue-500/20 text-blue-400',
                            'ковпачок' => 'bg-zinc-500/20 text-zinc-400',
                            'пакет'    => 'bg-amber-500/20 text-amber-400',
                            'прибори'  => 'bg-purple-500/20 text-purple-400',
                            'наклейка' => 'bg-pink-500/20 text-pink-400',
                            'серветка' => 'bg-cyan-500/20 text-cyan-400',
                        ];
                        $typeColor = $typeColors[$item['packaging_type']] ?? 'bg-zinc-700 text-zinc-400';
                    @endphp
                    <tr class="hover:bg-white/[0.02] transition">
                        <td class="px-5 py-3 font-semibold text-white">{{ $item['name'] }}</td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-block px-2 py-0.5 rounded text-xs font-semibold {{ $typeColor }}">
                                {{ $item['packaging_type'] }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-2xl font-black text-white">{{ $item['total_qty'] }}</span>
                            <span class="text-zinc-500 text-sm ml-1">шт</span>
                        </td>
                        <td class="px-5 py-3 text-right text-zinc-400">{{ number_format($item['unit_price'], 2, '.', ' ') }} ₴</td>
                        <td class="px-5 py-3 text-right font-semibold text-white">{{ number_format($item['total_cost'], 2, '.', ' ') }} ₴</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- СПИСОК ПО КЛІЄНТАХ --}}
    <div class="bg-zinc-900 border border-white/5 rounded-2xl overflow-hidden">
        <div class="px-5 py-4 border-b border-white/5">
            <h2 class="text-white font-bold text-lg">Деталізація по клієнтах</h2>
            <p class="text-zinc-500 text-sm">Що класти в пакет кожному клієнту</p>
        </div>

        @php
            $currentProject = null;
        @endphp

        @foreach($perClient as $client)
        {{-- Розділювач по бренду --}}
        @if($client['project_slug'] !== $currentProject)
            @php $currentProject = $client['project_slug']; @endphp
            <div class="px-5 py-2 bg-zinc-800/60 border-y border-white/5">
                <span class="text-xs font-bold uppercase tracking-widest text-zinc-400">{{ $client['project'] }}</span>
            </div>
        @endif

        <div class="px-5 py-4 border-b border-white/5 hover:bg-white/[0.01] transition">
            <div class="flex items-start justify-between gap-4">
                {{-- Інфо клієнта --}}
                <div class="min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-white font-bold">{{ $client['client_name'] }}</span>
                        <span class="text-xs px-2 py-0.5 rounded bg-zinc-700 text-zinc-300">{{ $client['calories'] }} ккал</span>
                        @if($client['project'] !== '—')
                            <span class="text-xs px-2 py-0.5 rounded bg-zinc-800 text-zinc-400">{{ $client['project'] }}</span>
                        @endif
                    </div>
                    @if($client['address'] !== '—')
                        <p class="text-zinc-500 text-xs">📍 {{ $client['address'] }}</p>
                    @endif
                </div>

                {{-- Вартість пакування клієнта --}}
                <div class="text-right flex-shrink-0">
                    <p class="text-zinc-600 text-xs">пакування</p>
                    <p class="text-emerald-400 font-bold">{{ number_format($client['total_cost'], 2, '.', ' ') }} ₴</p>
                </div>
            </div>

            {{-- Список позицій --}}
            <div class="mt-3 flex flex-wrap gap-2">
                @foreach($client['items'] as $item)
                @php
                    $bgColors = [
                        'бокс'     => 'border-emerald-500/30 bg-emerald-500/5',
                        'кришка'   => 'border-zinc-500/30 bg-zinc-500/5',
                        'пляшка'   => 'border-blue-500/30 bg-blue-500/5',
                        'ковпачок' => 'border-zinc-500/30 bg-zinc-500/5',
                        'пакет'    => 'border-amber-500/30 bg-amber-500/5',
                        'прибори'  => 'border-purple-500/30 bg-purple-500/5',
                        'серветка' => 'border-cyan-500/30 bg-cyan-500/5',
                        'наклейка' => 'border-pink-500/30 bg-pink-500/5',
                    ];
                    $bg = $bgColors[$item['packaging_type']] ?? 'border-zinc-700 bg-zinc-800/50';
                @endphp
                <div class="border rounded-lg px-3 py-1.5 text-xs {{ $bg }} flex items-center gap-1.5">
                    @if($item['auto_pair'])
                        <span class="text-zinc-500" title="Автоматична пара">⤷</span>
                    @endif
                    <span class="text-white font-medium">{{ $item['name'] }}</span>
                    @if($item['actual_weight'])
                        <span class="text-zinc-500">{{ $item['actual_weight'] }}г</span>
                    @endif
                    @if($item['dish_name'])
                        <span class="text-zinc-600">→ {{ $item['dish_name'] }}</span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    </div>

    @endif

</div>

<style>
    @media print {
        body { background: white !important; color: black !important; }
        .bg-zinc-950, .bg-zinc-900, .bg-zinc-800\/50 { background: white !important; }
        button { display: none !important; }
        form { display: none !important; }
        * { color: black !important; border-color: #ccc !important; }
        .text-emerald-400, .text-amber-400, .text-blue-400, .text-purple-400 { color: black !important; font-weight: bold !important; }
    }
</style>

</body>
</html>
