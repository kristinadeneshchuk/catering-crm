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

<div
    id="{{ $record->id }}"
    wire:click="recordClicked('{{ $record->id }}', {{ $record }})"
    style="
        border-left: 3px solid {{ $color }};
        background: {{ $bgAlpha }};
        border-radius: 10px;
        padding: 10px 12px;
        cursor: pointer;
        transition: box-shadow 0.15s ease, transform 0.1s ease;
        margin-bottom: 0;
        border-top: 1px solid rgba(255,255,255,0.06);
        border-right: 1px solid rgba(255,255,255,0.06);
        border-bottom: 1px solid rgba(255,255,255,0.06);
    "
    onmouseenter="this.style.boxShadow='0 2px 10px rgba(0,0,0,0.10)'; this.style.transform='translateY(-1px)';"
    onmouseleave="this.style.boxShadow='none'; this.style.transform='translateY(0)';"
>
    {{-- Row 1: Client name + urgency badge --}}
    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 6px;">
        <span style="font-weight: 700; font-size: 13px; color: #f3f4f6; line-height: 1.3; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
            {{ $client->name ?? 'Без імені' }}
        </span>
        <span style="
            background: {{ $badgeBg }};
            color: {{ $badgeColor }};
            border: 1px solid {{ $badgeColor }};
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            padding: 2px 7px;
            white-space: nowrap;
            flex-shrink: 0;
            letter-spacing: 0.01em;
        ">
            {{ $badgeText }}
        </span>
    </div>

    {{-- Row 2: Phone --}}
    <div style="margin-bottom: 5px;">
        <a
            href="tel:{{ $client->phone }}"
            @click.stop
            style="font-size: 11px; color: #6b7280; text-decoration: none; transition: color 0.1s;"
            onmouseenter="this.style.color='#3b82f6';"
            onmouseleave="this.style.color='#6b7280';"
        >
            {{ $client->phone ?? 'Немає номеру' }}
        </a>
    </div>

    {{-- Row 3: Kcal + end date --}}
    <div style="display: flex; gap: 12px; font-size: 11px; color: #9ca3af;">
        <span>
            <span style="color: #6b7280; font-weight: 500;">{{ $order->calories ?? '—' }} ккал</span>
        </span>
        <span style="color: #d1d5db;">|</span>
        <span>до <span style="color: #6b7280; font-weight: 500;">{{ $endDate->format('d.m.Y') }}</span></span>
    </div>

    {{-- Comment (if exists) --}}
    @if($record->comment)
        <div style="
            margin-top: 7px;
            padding-top: 7px;
            border-top: 1px solid rgba(255,255,255,0.07);
            font-size: 11px;
            color: #9ca3af;
            font-style: italic;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            line-height: 1.4;
        ">
            "{{ $record->comment }}"
        </div>
    @endif

    {{-- Next call reminder (if exists) --}}
    @if($record->next_call_at)
        <div style="
            margin-top: 6px;
            font-size: 11px;
            color: #f97316;
            font-weight: 500;
        ">
            Передзвонити: {{ \Carbon\Carbon::parse($record->next_call_at)->format('d.m H:i') }}
        </div>
    @endif
</div>
