@php
    $order  = $record->order;
    $client = $record->client;

    if (!$order || !$client) return;

    $endDate = \Carbon\Carbon::parse($order->end_date);
    $diff    = now()->startOfDay()->diffInDays($endDate->startOfDay(), false);

    if ($diff > 3) {
        $color      = '#3b82f6';
        $bgAlpha    = 'rgba(59, 130, 246, 0.04)';
        $badgeColor = '#3b82f6';
        $badgeBg    = 'rgba(59, 130, 246, 0.10)';
        $badgeText  = $diff . ' дн.';
    } elseif ($diff > 0) {
        $color      = '#f97316';
        $bgAlpha    = 'rgba(249, 115, 22, 0.04)';
        $badgeColor = '#f97316';
        $badgeBg    = 'rgba(249, 115, 22, 0.10)';
        $badgeText  = $diff . ' дн.';
    } elseif ($diff == 0) {
        $color      = '#ef4444';
        $bgAlpha    = 'rgba(239, 68, 68, 0.04)';
        $badgeColor = '#ef4444';
        $badgeBg    = 'rgba(239, 68, 68, 0.10)';
        $badgeText  = 'Сьогодні!';
    } else {
        $color      = '#6b7280';
        $bgAlpha    = 'rgba(107, 114, 128, 0.04)';
        $badgeColor = '#6b7280';
        $badgeBg    = 'rgba(107, 114, 128, 0.10)';
        $badgeText  = abs($diff) . ' дн. тому';
    }
@endphp

<style>
    .rc-card { border-radius: 10px; padding: 10px 12px; cursor: pointer; transition: box-shadow 0.15s ease, transform 0.1s ease; margin-bottom: 0; border-top: 1px solid rgba(0,0,0,0.06); border-right: 1px solid rgba(0,0,0,0.06); border-bottom: 1px solid rgba(0,0,0,0.06); }
    .rc-card:hover { box-shadow: 0 2px 10px rgba(0,0,0,0.10); transform: translateY(-1px); }
    .rc-name { font-weight: 700; font-size: 13px; color: #111827; line-height: 1.3; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .rc-phone { font-size: 11px; color: #4b5563; text-decoration: none; transition: color 0.1s; }
    .rc-phone:hover { color: #3b82f6; }
    .rc-meta { font-size: 11px; color: #6b7280; font-weight: 500; }
    .rc-sep { color: #9ca3af; }
    .rc-comment { font-size: 11px; color: #6b7280; font-style: italic; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.4; margin-top: 7px; padding-top: 7px; border-top: 1px solid rgba(0,0,0,0.07); }
    .dark .rc-card { border-top-color: rgba(255,255,255,0.06); border-right-color: rgba(255,255,255,0.06); border-bottom-color: rgba(255,255,255,0.06); }
    .dark .rc-name { color: #f3f4f6; }
    .dark .rc-phone { color: #9ca3af; }
    .dark .rc-meta { color: #9ca3af; }
    .dark .rc-sep { color: #4b5563; }
    .dark .rc-comment { color: #9ca3af; border-top-color: rgba(255,255,255,0.07); }
</style>

<div
    id="{{ $record->id }}"
    wire:click="recordClicked('{{ $record->id }}', {{ $record }})"
    class="rc-card"
    style="border-left: 3px solid {{ $color }}; background: {{ $bgAlpha }};"
>
    {{-- Row 1: Client name + profile link + urgency badge --}}
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px;">
        <div style="display: flex; align-items: center; gap: 5px; min-width: 0; flex: 1;">
            <a href="/admin/clients/{{ $client->id }}/edit" @click.stop target="_blank"
               style="display:flex; align-items:center; flex-shrink:0; color:#6b7280; transition:color 0.15s;"
               onmouseover="this.style.color='#3b82f6'" onmouseout="this.style.color='#6b7280'"
               title="Відкрити картку клієнта">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/>
                </svg>
            </a>
            <span class="rc-name">{{ $client->name ?? 'Без імені' }}</span>
        </div>
        <span style="background: {{ $badgeBg }}; color: {{ $badgeColor }}; border: 1px solid {{ $badgeColor }}; border-radius: 20px; font-size: 10px; font-weight: 600; padding: 2px 7px; white-space: nowrap; flex-shrink: 0; letter-spacing: 0.01em;">
            {{ $badgeText }}
        </span>
    </div>

    {{-- Row 2: Phone --}}
    <div style="margin-bottom: 5px;">
        <a href="tel:{{ $client->phone }}" @click.stop class="rc-phone">
            {{ $client->phone ?? 'Немає номеру' }}
        </a>
    </div>

    {{-- Row 3: Kcal + shift + end date --}}
    <div style="display: flex; gap: 12px; align-items: center;">
        <span class="rc-meta">{{ $order->calories ?? '—' }} ккал</span>
        <span class="rc-sep">|</span>
        @php
            $scheduleType = $order->schedule_type ?? '';
            $isEvening = str_contains($scheduleType, 'evening') || str_contains($scheduleType, 'вечір');
            $isMorning = str_contains($scheduleType, 'morning') || str_contains($scheduleType, 'ранок');
        @endphp
        @if($isEvening)
            <span style="font-size:11px; color:#7c3aed; font-weight:600;">Вечір</span>
            <span class="rc-sep">|</span>
        @elseif($isMorning)
            <span style="font-size:11px; color:#d97706; font-weight:600;">Ранок</span>
            <span class="rc-sep">|</span>
        @endif
        <span class="rc-meta">до {{ $endDate->format('d.m.Y') }}</span>
    </div>

    {{-- Comment (if exists) --}}
    @if($record->comment)
        <div class="rc-comment">"{{ $record->comment }}"</div>
    @endif

    {{-- Next call reminder (if exists) --}}
    @if($record->next_call_at)
        <div style="margin-top: 6px; font-size: 11px; color: #f97316; font-weight: 500;">
            Передзвонити: {{ \Carbon\Carbon::parse($record->next_call_at)->format('d.m H:i') }}
        </div>
    @endif
</div>
