@php
    $ind = $individualStats ?? [];
    $ret = $ind['retention'] ?? [];
    $cmp = $ind['comparison'] ?? [];
    $indCmp = $cmp['individual'] ?? [];
    $cycCmp = $cmp['cyclic'] ?? [];
@endphp

<div class="space-y-6">

    {{-- ЗАГОЛОВОК --}}
    <div>
        <h2 class="text-xl font-bold text-white">Індивідуальні клієнти</h2>
        <p class="text-zinc-500 text-sm mt-1">Аналітика клієнтів із персональним меню за обраний період</p>
    </div>

    @if(empty($ind) || ($ind['clients_count'] ?? 0) === 0)
        <div class="bg-zinc-900 border border-white/10 rounded-2xl p-12 text-center text-zinc-500">
            Немає індивідуальних клієнтів за обраний період
        </div>
    @else

    {{-- БЛОК 1: КЛЮЧОВІ МЕТРИКИ --}}
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-24 h-24 bg-violet-500/10 rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">Клієнтів</p>
            <h3 class="text-3xl font-black text-white">{{ $ind['clients_count'] }}</h3>
            <p class="text-zinc-600 text-xs mt-1">Унікальних за період</p>
        </div>

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-24 h-24 bg-emerald-500/10 rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">Дохід</p>
            <h3 class="text-3xl font-black text-white">{{ number_format($ind['revenue'] ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">
                @if(($ind['revenue_share'] ?? 0) > 0)
                    <span class="text-emerald-400 font-semibold">{{ round($ind['revenue_share'], 1) }}%</span> від загальної виручки
                @else
                    За обраний період
                @endif
            </p>
        </div>

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-24 h-24 bg-blue-500/10 rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">Середній чек</p>
            <h3 class="text-3xl font-black text-white">{{ number_format($ind['avg_check'] ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">На одне замовлення</p>
        </div>

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-24 h-24 bg-amber-500/10 rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">LTV</p>
            <h3 class="text-3xl font-black text-white">{{ number_format($ind['avg_ltv'] ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">Середній за всю історію</p>
        </div>

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-24 h-24 bg-sky-500/10 rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">Тривалість</p>
            <h3 class="text-3xl font-black text-white">{{ round($ind['avg_duration'] ?? 0, 1) }} <span class="text-base text-zinc-500 font-medium">днів</span></h3>
            <p class="text-zinc-600 text-xs mt-1">Середня тривалість замовлення</p>
        </div>

        <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-xl relative overflow-hidden">
            @php $margin = $ind['margin'] ?? 0; @endphp
            <div class="absolute top-0 right-0 w-24 h-24 {{ $margin >= 30 ? 'bg-emerald-500/10' : 'bg-orange-500/10' }} rounded-full blur-3xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[10px] font-bold uppercase tracking-widest mb-2">Маржа</p>
            <h3 class="text-3xl font-black {{ $margin >= 30 ? 'text-emerald-400' : ($margin >= 15 ? 'text-amber-400' : 'text-rose-400') }}">{{ round($margin, 1) }}%</h3>
            <p class="text-zinc-600 text-xs mt-1">{{ number_format($ind['rations_count'] ?? 0) }} раціонів</p>
        </div>

    </div>

    {{-- БЛОК 2: КАЛОРІЙНІСТЬ --}}
    @if(!empty($ind['calories']))
    <div class="bg-zinc-900 border border-white/10 rounded-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-white/5">
            <h3 class="text-white font-bold">Калорійність індивідуальних клієнтів</h3>
            <p class="text-zinc-500 text-sm mt-0.5">Розподіл раціонів, виручки та маржі по рівнях калорій</p>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-0">
            {{-- Таблиця --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-white/5">
                            <th class="text-left px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Калорій</th>
                            <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Клієнтів</th>
                            <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Раціонів</th>
                            <th class="text-right px-4 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Виручка</th>
                            <th class="text-right px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Маржа</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($ind['calories'] as $cal => $calData)
                        @php
                            $calColors = ['#f59e0b','#34d399','#60a5fa','#f472b6','#a78bfa','#fb923c','#2dd4bf','#e879f9'];
                            $calColor  = $calColors[$loop->index % count($calColors)];
                        @endphp
                        <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <div class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $calColor }};"></div>
                                    <span class="text-white font-semibold">{{ $cal }} ккал</span>
                                </div>
                            </td>
                            <td class="px-4 py-4 text-right text-zinc-300">{{ $calData['unique_clients'] }}</td>
                            <td class="px-4 py-4 text-right text-zinc-300">{{ $calData['count'] }}</td>
                            <td class="px-4 py-4 text-right text-white font-semibold">{{ number_format($calData['revenue'], 0, '.', ' ') }} ₴</td>
                            <td class="px-6 py-4 text-right font-semibold" style="color:{{ $calData['margin'] >= 30 ? '#34d399' : ($calData['margin'] >= 15 ? '#f59e0b' : '#f43f5e') }};">
                                {{ round($calData['margin'], 1) }}%
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-white/10 bg-zinc-950/50">
                            <td class="px-6 py-4 text-zinc-400 font-semibold text-sm">Разом</td>
                            <td class="px-4 py-4 text-right text-white font-bold">{{ $ind['clients_count'] }}</td>
                            <td class="px-4 py-4 text-right text-white font-bold">{{ $ind['rations_count'] }}</td>
                            <td class="px-4 py-4 text-right text-white font-bold">{{ number_format($ind['revenue'], 0, '.', ' ') }} ₴</td>
                            <td class="px-6 py-4 text-right text-white font-bold">{{ round($ind['margin'], 1) }}%</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            {{-- Donut chart --}}
            <div class="p-6 flex items-center justify-center border-l border-white/5">
                <div id="individualCalChart" class="w-full"></div>
            </div>
        </div>
    </div>
    @endif

    {{-- БЛОК 3: ПОРІВНЯННЯ ІНД vs ЦИКЛ --}}
    <div class="bg-zinc-900 border border-white/10 rounded-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-white/5">
            <h3 class="text-white font-bold">Індивідуальні vs Циклічні клієнти</h3>
            <p class="text-zinc-500 text-sm mt-0.5">Порівняння ключових метрик за обраний період</p>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-white/5">
                        <th class="text-left px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Метрика</th>
                        <th class="text-right px-6 py-3 text-[11px] uppercase tracking-wider font-semibold" style="color:#a78bfa;">Індивідуальні</th>
                        <th class="text-right px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Циклічні</th>
                        <th class="text-right px-6 py-3 text-zinc-500 text-[11px] uppercase tracking-wider font-semibold">Різниця</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $cmpRows = [
                            ['label' => 'Кількість клієнтів', 'key' => 'clients_count', 'format' => 'int',      'higher_is_better' => true],
                            ['label' => 'Середній чек',       'key' => 'avg_check',     'format' => 'currency', 'higher_is_better' => true],
                            ['label' => 'Середня тривалість', 'key' => 'avg_duration',  'format' => 'days',     'higher_is_better' => true],
                            ['label' => 'LTV',                'key' => 'avg_ltv',       'format' => 'currency', 'higher_is_better' => true],
                            ['label' => 'Маржа',              'key' => 'margin',        'format' => 'percent',  'higher_is_better' => true],
                            ['label' => 'Churn Rate',         'key' => 'churn_rate',    'format' => 'percent',  'higher_is_better' => false],
                        ];
                    @endphp
                    @foreach($cmpRows as $row)
                    @php
                        $iVal = $indCmp[$row['key']] ?? 0;
                        $cVal = $cycCmp[$row['key']] ?? 0;
                        $diff = $iVal - $cVal;
                        $indWins = $row['higher_is_better'] ? $iVal >= $cVal : $iVal <= $cVal;
                        $diffPct = $cVal > 0 ? abs($diff / $cVal) * 100 : 0;

                        $fmt = function($v, $type) {
                            if ($type === 'currency') return number_format($v, 0, '.', ' ') . ' ₴';
                            if ($type === 'percent')  return round($v, 1) . '%';
                            if ($type === 'days')     return round($v, 1) . ' дн.';
                            return (int) $v;
                        };
                    @endphp
                    <tr class="border-b border-white/5 hover:bg-white/[0.02] transition-colors">
                        <td class="px-6 py-4 text-zinc-400 font-medium">{{ $row['label'] }}</td>
                        <td class="px-6 py-4 text-right font-bold text-lg" style="color:{{ $indWins ? '#a78bfa' : '#71717a' }};">
                            {{ $fmt($iVal, $row['format']) }}
                        </td>
                        <td class="px-6 py-4 text-right text-zinc-300 font-semibold">{{ $fmt($cVal, $row['format']) }}</td>
                        <td class="px-6 py-4 text-right">
                            @if(abs($diff) > 0.01)
                                <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-1 rounded-full
                                    {{ $indWins ? 'bg-emerald-500/10 text-emerald-400' : 'bg-rose-500/10 text-rose-400' }}">
                                    {{ $indWins ? '↑' : '↓' }}
                                    {{ $diffPct > 0 ? round($diffPct, 1) . '%' : '' }}
                                    ({{ $diff > 0 ? '+' : '' }}{{ $fmt($diff, $row['format']) }})
                                </span>
                            @else
                                <span class="text-zinc-600 text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- БЛОК 4: УТРИМАННЯ ІНДИВІДУАЛЬНИХ КЛІЄНТІВ --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        {{-- Нові --}}
        <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-teal-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
            <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                <svg class="w-4 h-4 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
                Нові індивідуальні клієнти
            </p>
            <h3 class="text-4xl font-black text-white mb-1">{{ $ret['new_clients'] ?? 0 }}</h3>
            <p class="text-zinc-500 text-sm mb-3">Вперше замовили у цьому періоді</p>
            @if(($ret['new_clients'] ?? 0) > 0)
                <div class="flex gap-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                        <span class="text-zinc-400 text-sm">Продовжили&nbsp;<span class="text-emerald-400 font-bold">{{ $ret['new_clients_continued'] ?? 0 }}</span></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-rose-400 inline-block"></span>
                        <span class="text-zinc-400 text-sm">Відпали&nbsp;<span class="text-rose-400 font-bold">{{ $ret['new_clients_churned'] ?? 0 }}</span></span>
                    </div>
                </div>
            @endif
        </div>

        {{-- Відпали --}}
        <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
            @php $churnedPct = $ret['churned_period_percent'] ?? 0; @endphp
            <div class="absolute top-0 right-0 w-32 h-32 {{ $churnedPct > 30 ? 'bg-rose-500/10' : 'bg-orange-500/10' }} rounded-full blur-3xl -mr-10 -mt-10"></div>
            <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                <svg class="w-4 h-4 {{ $churnedPct > 30 ? 'text-rose-400' : 'text-orange-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                Відпали індивідуальні
            </p>
            <h3 class="text-4xl font-black {{ $churnedPct > 30 ? 'text-rose-400' : 'text-orange-400' }} mb-1">{{ $ret['churned_period'] ?? 0 }}</h3>
            <p class="text-zinc-500 text-sm">
                Підписка закінчилась у цьому періоді
                @if($churnedPct > 0)
                    &nbsp;&mdash;&nbsp;<span class="{{ $churnedPct > 30 ? 'text-rose-400' : 'text-orange-400' }} font-semibold">{{ round($churnedPct, 1) }}%</span>
                @endif
            </p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        {{-- LTV --}}
        <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-violet-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
            <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                <svg class="w-4 h-4 text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                LTV
            </p>
            <h3 class="text-4xl font-black text-white mb-1">{{ number_format($ret['avg_ltv'] ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-500 text-sm">В середньому приносить 1 клієнт</p>
        </div>

        {{-- Lifetime --}}
        <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
            <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                Середній час життя
            </p>
            <h3 class="text-4xl font-black text-white mb-1">{{ round($ret['avg_lifetime_days'] ?? 0, 1) }} <span class="text-xl text-zinc-500 font-medium">днів</span></h3>
            <p class="text-zinc-500 text-sm">Тривалість роботи з клієнтом</p>
        </div>

        {{-- Churn rate --}}
        <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
            @php $churn = $ret['churn_rate'] ?? 0; @endphp
            <div class="absolute top-0 right-0 w-32 h-32 {{ $churn > 40 ? 'bg-rose-500/10' : 'bg-yellow-500/10' }} rounded-full blur-3xl -mr-10 -mt-10"></div>
            <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
                <svg class="w-4 h-4 {{ $churn > 40 ? 'text-rose-400' : 'text-yellow-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg>
                Churn Rate
            </p>
            <h3 class="text-4xl font-black {{ $churn > 40 ? 'text-rose-400' : 'text-yellow-400' }} mb-1">{{ round($churn, 1) }}%</h3>
            <p class="text-zinc-500 text-sm">Без активних підписок</p>
        </div>
    </div>

    {{-- Сегментація --}}
    @if(($ret['total_clients'] ?? 0) > 0)
    <div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-white/5">
            <h3 class="text-lg text-white font-bold mb-1">Сегментація за тривалістю</h3>
            <p class="text-zinc-500 text-sm">Тільки індивідуальні клієнти</p>
        </div>
        <div class="p-6">
            <div class="flex h-6 rounded-full overflow-hidden mb-6">
                @foreach($ret['segments'] as $segment)
                    @php $pct = ($ret['total_clients'] > 0) ? ($segment['count'] / $ret['total_clients']) * 100 : 0; @endphp
                    @if($pct > 0)
                        <div class="{{ $segment['color'] }} h-full transition-all duration-500 flex items-center justify-center text-[10px] font-bold text-white/80"
                             style="width:{{ $pct }}%" title="{{ $segment['label'] }}">
                            {{ $pct > 10 ? round($pct).'%' : '' }}
                        </div>
                    @endif
                @endforeach
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($ret['segments'] as $segment)
                <div class="flex items-center gap-3 p-4 bg-zinc-800/50 rounded-xl border border-white/5">
                    <div class="w-4 h-4 rounded-full {{ $segment['color'] }} flex-shrink-0 shadow-lg"></div>
                    <div>
                        <p class="text-zinc-400 text-xs font-bold uppercase tracking-wider mb-0.5">{{ $segment['label'] }}</p>
                        <p class="text-white font-semibold text-lg">{{ $segment['count'] }} <span class="text-zinc-500 text-sm font-normal">клієнтів</span></p>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    @endif {{-- end @if clients_count > 0 --}}
</div>

<script>
@if(!empty($ind['calories']) && ($ind['clients_count'] ?? 0) > 0)
@php
    $calLabels  = array_map(fn($c) => $c . ' ккал', array_keys($ind['calories']));
    $calSeries  = array_map(fn($d) => (int) $d['count'], array_values($ind['calories']));
    $calColors  = array_slice(['#f59e0b','#34d399','#60a5fa','#f472b6','#a78bfa','#fb923c','#2dd4bf','#e879f9'], 0, count($calLabels));
@endphp
if (document.querySelector("#individualCalChart")) {
    new ApexCharts(document.querySelector("#individualCalChart"), {
        series: {!! json_encode($calSeries) !!},
        labels: {!! json_encode($calLabels) !!},
        chart: { type: 'donut', height: 280, background: 'transparent', fontFamily: 'Inter, sans-serif' },
        colors: {!! json_encode($calColors) !!},
        plotOptions: {
            pie: { donut: { size: '70%', labels: {
                show: true,
                total: { show: true, label: 'Раціонів', color: '#71717a',
                    formatter: w => w.globals.seriesNumbers.reduce((a,b)=>a+b,0)
                },
                value: { color: '#fff', fontSize: '22px', fontWeight: '900' }
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
