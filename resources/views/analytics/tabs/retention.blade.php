<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
    <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-teal-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
            <svg class="w-4 h-4 text-teal-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
            Нові клієнти
        </p>
        <h3 class="text-4xl font-black text-white mb-1">{{ $retentionStats['new_clients'] ?? 0 }}</h3>
        <p class="text-zinc-500 text-sm mb-3">
            Вперше замовили у цьому періоді
            @if(($retentionStats['new_clients_percent'] ?? 0) > 0)
                &nbsp;&mdash;&nbsp;<span class="text-teal-400 font-semibold">{{ round($retentionStats['new_clients_percent'], 1) }}%</span> від усіх клієнтів
            @endif
        </p>
        @if(($retentionStats['new_clients'] ?? 0) > 0)
            <div class="flex gap-4 mt-1">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                    <span class="text-zinc-400 text-sm">Продовжили&nbsp;<span class="text-emerald-400 font-bold">{{ $retentionStats['new_clients_continued'] ?? 0 }}</span></span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-rose-400 inline-block"></span>
                    <span class="text-zinc-400 text-sm">Відпали&nbsp;<span class="text-rose-400 font-bold">{{ $retentionStats['new_clients_churned'] ?? 0 }}</span></span>
                </div>
            </div>
        @endif
    </div>

    <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        @php $churnedPeriodPct = $retentionStats['churned_period_percent'] ?? 0; @endphp
        <div class="absolute top-0 right-0 w-32 h-32 {{ $churnedPeriodPct > 30 ? 'bg-rose-500/10' : 'bg-orange-500/10' }} rounded-full blur-3xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
            <svg class="w-4 h-4 {{ $churnedPeriodPct > 30 ? 'text-rose-400' : 'text-orange-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
            Відпали клієнти
        </p>
        <h3 class="text-4xl font-black {{ $churnedPeriodPct > 30 ? 'text-rose-400' : 'text-orange-400' }} mb-1">{{ $retentionStats['churned_period'] ?? 0 }}</h3>
        <p class="text-zinc-500 text-sm">
            Підписка закінчилась у цьому періоді
            @if($churnedPeriodPct > 0)
                &nbsp;&mdash;&nbsp;<span class="{{ $churnedPeriodPct > 30 ? 'text-rose-400' : 'text-orange-400' }} font-semibold">{{ round($churnedPeriodPct, 1) }}%</span> від усіх клієнтів
            @endif
        </p>
    </div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
    <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
            <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08-.402-2.599-1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            Lifetime Value (LTV)
        </p>
        <h3 class="text-4xl font-black text-white mb-1">{{ number_format($retentionStats['avg_ltv'] ?? 0, 0, '.', ' ') }} ₴</h3>
        <p class="text-zinc-500 text-sm">В середньому приносить 1 клієнт</p>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-32 h-32 bg-blue-500/10 rounded-full blur-3xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
            <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            Середній час життя
        </p>
        <h3 class="text-4xl font-black text-white mb-1">{{ round($retentionStats['avg_lifetime_days'] ?? 0, 1) }} <span class="text-xl text-zinc-500 font-medium">днів</span></h3>
        <p class="text-zinc-500 text-sm">Тривалість роботи з клієнтом</p>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-6 rounded-2xl shadow-xl relative overflow-hidden">
        @php $churn = $retentionStats['churn_rate'] ?? 0; @endphp
        <div class="absolute top-0 right-0 w-32 h-32 {{ $churn > 40 ? 'bg-rose-500/10' : 'bg-yellow-500/10' }} rounded-full blur-3xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-xs font-bold uppercase tracking-widest mb-2 flex items-center gap-2">
            <svg class="w-4 h-4 {{ $churn > 40 ? 'text-rose-400' : 'text-yellow-400' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg>
            Churn Rate (Відтік)
        </p>
        <h3 class="text-4xl font-black {{ $churn > 40 ? 'text-rose-400' : 'text-yellow-400' }} mb-1">{{ round($churn, 1) }}%</h3>
        <p class="text-zinc-500 text-sm">Клієнтів не мають активних підписок</p>
    </div>
</div>

<div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden mb-8">
    <div class="p-6 border-b border-white/5">
        <h3 class="text-lg text-white font-bold mb-1">Сегментація клієнтів за тривалістю</h3>
        <p class="text-zinc-500 text-sm">Розподіл клієнтів, які робили замовлення в обраний період.</p>
    </div>
    
    <div class="p-6">
        @if(($retentionStats['total_clients'] ?? 0) > 0)
            <div class="flex h-6 rounded-full overflow-hidden mb-6">
                @foreach($retentionStats['segments'] as $segment)
                    @php $percent = ($segment['count'] / $retentionStats['total_clients']) * 100; @endphp
                    @if($percent > 0)
                        <div class="{{ $segment['color'] }} h-full transition-all duration-500 flex items-center justify-center text-[10px] font-bold text-white/80" style="width: {{ $percent }}%" title="{{ $segment['label'] }}">
                            {{ $percent > 10 ? round($percent).'%' : '' }}
                        </div>
                    @endif
                @endforeach
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                @foreach($retentionStats['segments'] as $key => $segment)
                    <div class="flex items-center gap-3 p-4 bg-zinc-800/50 rounded-xl border border-white/5">
                        <div class="w-4 h-4 rounded-full {{ $segment['color'] }} flex-shrink-0 shadow-lg"></div>
                        <div>
                            <p class="text-zinc-400 text-xs font-bold uppercase tracking-wider mb-0.5">{{ $segment['label'] }}</p>
                            <p class="text-white font-semibold text-lg">{{ $segment['count'] }} <span class="text-zinc-500 text-sm font-normal">клієнтів</span></p>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="text-center py-8 text-zinc-500">Немає даних про клієнтів за обраний період.</div>
        @endif
    </div>
</div>