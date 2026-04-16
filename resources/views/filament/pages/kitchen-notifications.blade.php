<x-filament-panels::page>
@php
    $unread = $this->getViewData()['unread'];
    $read   = $this->getViewData()['read'];
@endphp

<div class="space-y-6">

    {{-- НЕПРОЧИТАНІ --}}
    @if($unread->isNotEmpty())
    <div>
        <h3 class="text-sm font-bold uppercase tracking-widest text-zinc-500 mb-3 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-rose-500 animate-pulse inline-block"></span>
            Нові ({{ $unread->count() }})
        </h3>
        <div class="space-y-2">
            @foreach($unread as $n)
            <div wire:click="markRead({{ $n->id }})"
                 class="group flex items-start gap-4 p-4 rounded-2xl border cursor-pointer transition-all
                        {{ $n->has_exclusions ? 'bg-rose-950/40 border-rose-500/30 hover:border-rose-500/60' : 'bg-zinc-900 border-white/10 hover:border-white/20' }}">

                {{-- Type icon --}}
                <div class="flex-shrink-0 w-10 h-10 rounded-xl flex items-center justify-center mt-0.5
                            {{ $n->type === 'new_client' ? 'bg-emerald-500/15' : 'bg-blue-500/15' }}">
                    @if($n->type === 'new_client')
                        <svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                    @else
                        <svg class="w-5 h-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                        </svg>
                    @endif
                </div>

                {{-- Info --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <span class="text-xs font-bold uppercase tracking-wider
                                     {{ $n->type === 'new_client' ? 'text-emerald-400' : 'text-blue-400' }}">
                            {{ $n->type === 'new_client' ? 'Новий клієнт' : 'Продовження' }}
                        </span>
                        @if($n->has_exclusions)
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-rose-500/20 text-rose-400 uppercase tracking-wider">
                                ⚠ Є алергени/виключення
                            </span>
                        @endif
                        @if($n->project)
                            <span class="text-[10px] px-2 py-0.5 rounded-full bg-zinc-800 text-zinc-400">
                                {{ $n->project }}
                            </span>
                        @endif
                    </div>
                    <p class="text-white font-semibold">{{ $n->client_name }}</p>
                    <div class="flex items-center gap-4 mt-1 flex-wrap">
                        <span class="text-zinc-400 text-sm">{{ $n->calories }} ккал</span>
                        <span class="text-zinc-400 text-sm">{{ $n->duration }} дн.</span>
                        <span class="text-zinc-400 text-sm">З {{ $n->start_date->format('d.m.Y') }}</span>
                        @if($n->schedule_type)
                            @php
                                $scheduleLabels = [
                                    'every_day_morning'    => '🌅 Кожен день, ранок',
                                    'every_day_evening'    => '🌙 Кожен день, вечір',
                                    'individual_morning'   => '🌅 Індивід. графік, ранок',
                                    'individual_evening'   => '🌙 Індивід. графік, вечір',
                                ];
                            @endphp
                            <span class="text-zinc-500 text-sm">
                                {{ $scheduleLabels[$n->schedule_type] ?? $n->schedule_type }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Time + read button --}}
                <div class="flex-shrink-0 text-right">
                    <p class="text-zinc-600 text-xs">{{ $n->created_at->format('H:i') }}</p>
                    <p class="text-zinc-700 text-xs">{{ $n->created_at->format('d.m') }}</p>
                    <span class="text-[10px] text-zinc-600 group-hover:text-zinc-400 transition-colors mt-2 block">
                        Натисни → прочитано
                    </span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- ПРОЧИТАНІ --}}
    @if($read->isNotEmpty())
    <div>
        <h3 class="text-sm font-bold uppercase tracking-widest text-zinc-700 mb-3 flex items-center gap-2">
            <svg class="w-4 h-4 text-zinc-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            Прочитані ({{ $read->count() }})
        </h3>
        <div class="space-y-1.5">
            @foreach($read->take(30) as $n)
            <div class="flex items-center gap-3 p-3 rounded-xl bg-zinc-900/50 border border-white/5 opacity-50">
                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0
                            {{ $n->type === 'new_client' ? 'bg-emerald-500/10' : 'bg-blue-500/10' }}">
                    <svg class="w-4 h-4 {{ $n->type === 'new_client' ? 'text-emerald-600' : 'text-blue-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-zinc-500 text-sm truncate">{{ $n->message }}</p>
                </div>
                <span class="text-zinc-700 text-xs flex-shrink-0">{{ $n->created_at->format('d.m H:i') }}</span>
            </div>
            @endforeach
        </div>
    </div>
    @endif

    @if($unread->isEmpty() && $read->isEmpty())
        <div class="text-center py-16 text-zinc-600">
            <svg class="w-12 h-12 mx-auto mb-4 text-zinc-800" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <p class="text-zinc-600">Сповіщень поки немає</p>
        </div>
    @endif

</div>
</x-filament-panels::page>
