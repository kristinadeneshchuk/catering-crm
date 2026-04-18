<x-filament-panels::page>
@php
    $unread = $this->getViewData()['unread'];
    $read   = $this->getViewData()['read'];

    $typeConfig = [
        'new_client' => ['label' => 'Новий клієнт',  'accent' => '#4ade80', 'bg' => 'rgba(74,222,128,0.08)',  'border' => 'rgba(74,222,128,0.25)'],
        'renewal'    => ['label' => 'Продовження',   'accent' => '#38bdf8', 'bg' => 'rgba(56,189,248,0.08)',  'border' => 'rgba(56,189,248,0.25)'],
        'reward'     => ['label' => 'Нагорода',       'accent' => '#a78bfa', 'bg' => 'rgba(167,139,250,0.08)', 'border' => 'rgba(167,139,250,0.25)'],
    ];

    $scheduleLabels = [
        'every_day_morning'  => '🌅 Кожен день, ранок',
        'every_day_evening'  => '🌙 Кожен день, вечір',
        'individual_morning' => '🌅 Індивід., ранок',
        'individual_evening' => '🌙 Індивід., вечір',
    ];
@endphp

<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── НОВІ ── --}}
    @if($unread->isNotEmpty())
    <div style="background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;position:relative;">
        <div style="position:absolute;top:0;left:0;right:0;height:2px;background:#f87171;border-radius:0.875rem 0.875rem 0 0;"></div>

        {{-- Заголовок --}}
        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:0.625rem;">
            <div style="width:0.5rem;height:0.5rem;border-radius:50%;background:#f87171;animation:pulse 2s infinite;flex-shrink:0;"></div>
            <span style="font-size:0.8rem;font-weight:700;color:#f1f5f9;">Нові</span>
            <span style="background:#f87171;color:white;font-size:0.7rem;font-weight:700;padding:0.1rem 0.5rem;border-radius:100px;">{{ $unread->count() }}</span>
        </div>

        {{-- Список --}}
        @foreach($unread as $n)
        @php
            $cfg    = $typeConfig[$n->type] ?? $typeConfig['renewal'];
            $accent = $cfg['accent'];
        @endphp
        <div wire:click="markRead({{ $n->id }})"
             style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;align-items:flex-start;gap:0.875rem;cursor:pointer;transition:background 0.15s;position:relative;overflow:hidden;
                    {{ $n->has_exclusions ? 'background:rgba(248,113,113,0.05);' : '' }}"
             onmouseover="this.style.background='rgba(255,255,255,0.03)'"
             onmouseout="this.style.background='{{ $n->has_exclusions ? 'rgba(248,113,113,0.05)' : 'transparent' }}'">

            {{-- Акцентна ліва смужка --}}
            <div style="position:absolute;left:0;top:0;bottom:0;width:3px;background:{{ $accent }};border-radius:0 2px 2px 0;"></div>

            {{-- Іконка типу --}}
            <div style="width:2.5rem;height:2.5rem;border-radius:0.625rem;background:{{ $cfg['bg'] }};border:1px solid {{ $cfg['border'] }};display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-left:0.5rem;">
                @if($n->type === 'new_client')
                    <svg style="width:1.1rem;height:1.1rem;color:{{ $accent }};" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                @elseif($n->type === 'reward')
                    <span style="font-size:1.1rem;">🎁</span>
                @else
                    <svg style="width:1.1rem;height:1.1rem;color:{{ $accent }};" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                @endif
            </div>

            {{-- Контент --}}
            <div style="flex:1;min-width:0;">
                {{-- Рядок 1: тип + проєкт + алерт --}}
                <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                    <span style="font-size:0.7rem;font-weight:800;color:{{ $accent }};text-transform:uppercase;letter-spacing:0.06em;">
                        {{ $cfg['label'] }}
                    </span>
                    @if($n->project)
                        <span style="font-size:0.65rem;font-weight:600;padding:0.1rem 0.5rem;border-radius:100px;background:rgba(255,255,255,0.06);color:#9ca3af;">
                            {{ $n->project }}
                        </span>
                    @endif
                    @if($n->has_exclusions)
                        <span style="font-size:0.65rem;font-weight:700;padding:0.1rem 0.5rem;border-radius:100px;background:rgba(248,113,113,0.15);color:#f87171;">
                            ⚠ Алергени/виключення
                        </span>
                    @endif
                </div>

                {{-- Ім'я клієнта --}}
                <p style="font-size:0.9rem;font-weight:700;color:#f1f5f9;margin-bottom:0.375rem;">{{ $n->client_name }}</p>

                {{-- Деталі --}}
                @if($n->type !== 'reward')
                <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                    @if($n->calories)
                        <span style="font-size:0.75rem;color:#9ca3af;">{{ $n->calories }} ккал</span>
                    @endif
                    @if($n->duration)
                        <span style="font-size:0.7rem;color:#6b7280;">·</span>
                        <span style="font-size:0.75rem;color:#9ca3af;">{{ $n->duration }} дн.</span>
                    @endif
                    @if($n->start_date)
                        <span style="font-size:0.7rem;color:#6b7280;">·</span>
                        <span style="font-size:0.75rem;color:#9ca3af;">З {{ $n->start_date->format('d.m.Y') }}</span>
                    @endif
                    @if($n->schedule_type)
                        <span style="font-size:0.7rem;color:#6b7280;">·</span>
                        <span style="font-size:0.72rem;color:#6b7280;">{{ $scheduleLabels[$n->schedule_type] ?? $n->schedule_type }}</span>
                    @endif
                </div>
                @else
                    <p style="font-size:0.75rem;color:#9ca3af;font-style:italic;">{{ $n->message }}</p>
                @endif
            </div>

            {{-- Час + підказка --}}
            <div style="flex-shrink:0;text-align:right;">
                <p style="font-size:0.8rem;font-weight:700;color:#9ca3af;">{{ $n->created_at->format('H:i') }}</p>
                <p style="font-size:0.7rem;color:#4b5563;">{{ $n->created_at->format('d.m') }}</p>
                <p style="font-size:0.65rem;color:#374151;margin-top:0.375rem;">Натисни → прочитано</p>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── ПРОЧИТАНІ ── --}}
    @if($read->isNotEmpty())
    <div style="background:rgba(255,255,255,0.015);border:1px solid rgba(255,255,255,0.05);border-radius:0.875rem;overflow:hidden;">
        <div style="padding:0.75rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.04);display:flex;align-items:center;gap:0.5rem;">
            <svg style="width:0.875rem;height:0.875rem;color:#374151;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <span style="font-size:0.75rem;font-weight:600;color:#4b5563;">Прочитані ({{ $read->count() }})</span>
        </div>
        @foreach($read->take(50) as $n)
        @php
            $cfg    = $typeConfig[$n->type] ?? $typeConfig['renewal'];
            $accent = $cfg['accent'];
        @endphp
        <div style="padding:0.625rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.03);display:flex;align-items:center;gap:0.75rem;opacity:0.4;">
            <div style="width:1.75rem;height:1.75rem;border-radius:0.375rem;background:{{ $cfg['bg'] }};display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                @if($n->type === 'reward')
                    <span style="font-size:0.75rem;">🎁</span>
                @else
                    <svg style="width:0.75rem;height:0.75rem;color:{{ $accent }};" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                @endif
            </div>
            <div style="flex:1;min-width:0;">
                <span style="font-size:0.75rem;font-weight:600;color:#6b7280;">{{ $n->client_name }}</span>
                <span style="font-size:0.7rem;color:#374151;margin-left:0.5rem;">· {{ $cfg['label'] }}</span>
                @if($n->calories)
                    <span style="font-size:0.7rem;color:#374151;margin-left:0.375rem;">· {{ $n->calories }} ккал</span>
                @endif
            </div>
            <span style="font-size:0.7rem;color:#374151;flex-shrink:0;">{{ $n->created_at->format('d.m H:i') }}</span>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── ПОРОЖНЬО ── --}}
    @if($unread->isEmpty() && $read->isEmpty())
    <div style="padding:5rem 1rem;text-align:center;">
        <div style="font-size:3rem;color:rgba(255,255,255,0.06);margin-bottom:1rem;">🔔</div>
        <p style="font-size:0.9rem;font-weight:600;color:#4b5563;">Сповіщень немає</p>
        <p style="font-size:0.75rem;color:#374151;margin-top:0.25rem;">Нові замовлення і продовження з'являться тут</p>
    </div>
    @endif

</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50%       { opacity: 0.3; }
}
</style>
</x-filament-panels::page>
