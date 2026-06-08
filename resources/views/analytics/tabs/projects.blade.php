@php
    $colors = ['#f59e0b','#34d399','#60a5fa','#f472b6','#a78bfa','#fb923c','#2dd4bf','#e879f9'];
    $totalRev = collect($projectStats)->sum('revenue');
@endphp

<div class="space-y-6">

    {{-- ЗАГОЛОВОК --}}
    <div>
        <h2 class="text-xl font-bold text-white">Аналітика по проєктах</h2>
        <p class="text-zinc-500 text-sm mt-1">Розподіл виручки, раціонів та профіту між брендами за обраний період</p>
    </div>

    @if(empty($projectStats))
        <div class="bg-zinc-900 border border-white/10 rounded-2xl p-12 text-center text-zinc-500">
            Немає даних за обраний період
        </div>
    @else

    {{-- КАРТКИ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @foreach($projectStats as $slug => $ps)
        @php $color = $colors[$loop->index % count($colors)]; @endphp
        <div class="bg-zinc-900 border border-white/10 rounded-2xl p-5 relative overflow-hidden">
            <div style="position:absolute;top:0;left:0;width:4px;height:100%;background:{{ $color }};border-radius:12px 0 0 12px;"></div>
            <div class="pl-2">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-white font-bold text-base">{{ $ps['name'] }}</span>
                    <span class="text-xs font-bold px-2 py-1 rounded-full" style="background:{{ $color }}22;color:{{ $color }};">
                        {{ number_format($ps['revenue_share'], 1) }}%
                    </span>
                </div>

                {{-- Прогрес-бар частки --}}
                <div class="w-full bg-zinc-800 rounded-full h-1.5 mb-4">
                    <div class="h-1.5 rounded-full" style="width:{{ min(100, $ps['revenue_share']) }}%;background:{{ $color }};"></div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Виручка</p>
                        <p class="text-white font-bold text-lg">{{ number_format($ps['revenue'], 0, '.', ' ') }} ₴</p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Профіт</p>
                        <p class="font-bold text-lg" style="color:{{ $ps['profit'] >= 0 ? '#34d399' : '#f43f5e' }};">
                            {{ number_format($ps['profit'], 0, '.', ' ') }} ₴
                        </p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Раціонів</p>
                        <p class="text-white font-semibold">{{ $ps['rations'] }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Клієнтів</p>
                        <p class="text-white font-semibold">{{ $ps['unique_clients'] }}</p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Маржа</p>
                        <p class="font-semibold" style="color:{{ $ps['margin'] >= 30 ? '#34d399' : ($ps['margin'] >= 15 ? '#f59e0b' : '#f43f5e') }};">
                            {{ number_format($ps['margin'], 1) }}%
                        </p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">Собівартість</p>
                        <p class="text-zinc-400 font-semibold">{{ number_format($ps['food_cost'] + $ps['packaging'], 0, '.', ' ') }} ₴</p>
                    </div>
                    <div>
                        <p class="text-zinc-500 text-[10px] uppercase tracking-wider font-semibold mb-1">ЗП співробітників</p>
                        <p class="text-zinc-400 font-semibold">{{ number_format($ps['salary'] ?? 0, 0, '.', ' ') }} ₴</p>
                    </div>
                </div>

                {{-- НОВІ / ВІДПАЛИ за період (per-project) --}}
                <div class="grid grid-cols-2 gap-2 mt-4 pt-4 border-t border-white/5">
                    <div class="bg-emerald-500/5 border border-emerald-500/10 rounded-lg p-2.5">
                        <div class="flex items-center gap-1.5 mb-1">
                            <svg class="w-3 h-3 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                            <span class="text-emerald-400 text-[9px] uppercase tracking-wider font-bold">Нові</span>
                        </div>
                        <p class="text-white text-xl font-black leading-none mb-1">{{ $ps['new_clients'] ?? 0 }}</p>
                        @if(($ps['new_clients'] ?? 0) > 0)
                            <p class="text-[10px] text-zinc-500">
                                <span class="text-emerald-400 font-bold">{{ $ps['new_clients_continued'] ?? 0 }}</span> продовж.,
                                <span class="text-rose-400 font-bold">{{ $ps['new_clients_churned'] ?? 0 }}</span> від.
                            </p>
                        @endif
                    </div>
                    <div class="bg-rose-500/5 border border-rose-500/10 rounded-lg p-2.5">
                        <div class="flex items-center gap-1.5 mb-1">
                            <svg class="w-3 h-3 text-rose-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                            <span class="text-rose-400 text-[9px] uppercase tracking-wider font-bold">Відпали</span>
                        </div>
                        <p class="text-white text-xl font-black leading-none mb-1">{{ $ps['churned_period'] ?? 0 }}</p>
                        @if(($ps['churned_period_percent'] ?? 0) > 0)
                            <p class="text-[10px] text-zinc-500">{{ number_format($ps['churned_period_percent'], 1) }}% від клієнтів</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- ЗВЕДЕНА ТАБЛИЦЯ --}}
    <div class="bg-zinc-900 border border-white/10 rounded-2xl overflow-hidden">
        <div class="px-6 py-4 border-b border-white/10">
            <h3 class="text-white font-bold">Порівняльна таблиця</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Проєкт</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Раціонів</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Виручка</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Собівартість</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">ЗП</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Профіт</th>
                        <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Маржа</th>
                        <th class="text-right px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Частка</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($projectStats as $slug => $ps)
                    @php $color = $colors[$loop->index % count($colors)]; @endphp
                    <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <div class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $color }};"></div>
                                <span class="text-white font-semibold">{{ $ps['name'] }}</span>
                                <span class="text-zinc-600 text-xs">{{ $ps['unique_clients'] }} кл.</span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-right text-zinc-300">{{ $ps['rations'] }}</td>
                        <td class="px-4 py-4 text-right text-white font-semibold">{{ number_format($ps['revenue'], 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right text-zinc-400">{{ number_format($ps['food_cost'] + $ps['packaging'], 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right text-zinc-400">{{ number_format($ps['salary'] ?? 0, 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right font-bold" style="color:{{ $ps['profit'] >= 0 ? '#34d399' : '#f43f5e' }};">
                            {{ number_format($ps['profit'], 0, '.', ' ') }} ₴
                        </td>
                        <td class="px-4 py-4 text-right font-semibold" style="color:{{ $ps['margin'] >= 30 ? '#34d399' : ($ps['margin'] >= 15 ? '#f59e0b' : '#f43f5e') }};">
                            {{ number_format($ps['margin'], 1) }}%
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <div class="w-16 bg-zinc-800 rounded-full h-1.5">
                                    <div class="h-1.5 rounded-full" style="width:{{ min(100,$ps['revenue_share']) }}%;background:{{ $color }};"></div>
                                </div>
                                <span class="text-zinc-400 text-xs w-10 text-right">{{ number_format($ps['revenue_share'], 1) }}%</span>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-white/10 bg-zinc-950/50">
                        <td class="px-6 py-4 text-zinc-400 font-semibold text-sm">Разом</td>
                        <td class="px-4 py-4 text-right text-white font-bold">{{ collect($projectStats)->sum('rations') }}</td>
                        <td class="px-4 py-4 text-right text-white font-bold">{{ number_format(collect($projectStats)->sum('revenue'), 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right text-zinc-400 font-semibold">{{ number_format(collect($projectStats)->sum('food_cost') + collect($projectStats)->sum('packaging'), 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right font-bold text-emerald-400">{{ number_format(collect($projectStats)->sum('profit'), 0, '.', ' ') }} ₴</td>
                        <td class="px-4 py-4 text-right text-zinc-500">—</td>
                        <td class="px-6 py-4 text-right text-white font-bold">100%</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    {{-- КРУГОВА ДІАГРАМА --}}
    <div class="bg-zinc-900 border border-white/10 rounded-2xl p-6">
        <h3 class="text-white font-bold mb-4">Розподіл виручки</h3>
        <div id="projectsChart"></div>
    </div>

    @endif
</div>

<script>
@if(!empty($projectStats))
@php
    $pLabels = collect($projectStats)->pluck('name')->values()->toArray();
    $pSeries = collect($projectStats)->pluck('revenue')->map(fn($v) => round($v))->values()->toArray();
    $pColors = array_slice(['#f59e0b','#34d399','#60a5fa','#f472b6','#a78bfa','#fb923c','#2dd4bf','#e879f9'], 0, count($pLabels));
@endphp
if (document.querySelector("#projectsChart")) {
    new ApexCharts(document.querySelector("#projectsChart"), {
        series: {!! json_encode($pSeries) !!},
        labels: {!! json_encode($pLabels) !!},
        chart: { type: 'donut', height: 320, background: 'transparent', fontFamily: 'Inter, sans-serif' },
        colors: {!! json_encode($pColors) !!},
        plotOptions: {
            pie: { donut: { size: '70%', labels: {
                show: true,
                total: { show: true, label: 'Загальна виручка', color: '#71717a',
                    formatter: w => w.globals.seriesNumbers.reduce((a,b)=>a+b,0).toLocaleString() + ' ₴'
                },
                value: { color: '#fff', fontSize: '22px', fontWeight: '900',
                    formatter: v => Number(v).toLocaleString() + ' ₴'
                }
            }}}
        },
        stroke: { show: true, colors: ['#18181b'], width: 3 },
        dataLabels: { enabled: false },
        legend: { position: 'bottom', labels: { colors: '#71717a' } },
        theme: { mode: 'dark' }
    }).render();
}
@endif
</script>
