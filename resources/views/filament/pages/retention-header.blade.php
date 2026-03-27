@php
    $statusColors = [
        'new'       => '#3b82f6',
        'no_answer' => '#eab308',
        'thinking'  => '#f97316',
        'refused'   => '#ef4444',
        'success'   => '#22c55e',
    ];

    $statusId    = $status['id'] ?? '';
    $dotColor    = $statusColors[$statusId] ?? '#6b7280';
    $count       = isset($status['records']) ? count($status['records']) : 0;

    // Strip emoji / non-ASCII symbols from the title so the header stays clean
    $rawTitle    = $status['title'] ?? $statusId;
    $cleanTitle  = trim(preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FEFF}]+/u', '', $rawTitle));
@endphp

<div style="
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 2px 10px 2px;
    border-bottom: 1px solid rgba(0,0,0,0.07);
    margin-bottom: 8px;
">
    <div style="display: flex; align-items: center; gap: 8px;">
        {{-- Colored dot --}}
        <span style="
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: {{ $dotColor }};
            display: inline-block;
            flex-shrink: 0;
            box-shadow: 0 0 0 2px {{ $dotColor }}22;
        "></span>

        {{-- Column title --}}
        <span class="rh-title" style="font-size: 13px; font-weight: 600; line-height: 1.2; letter-spacing: -0.01em;">
            {{ $cleanTitle }}
        </span>
        <style>
            .rh-title { color: #374151; }
            .dark .rh-title { color: #e5e7eb; }
        </style>
    </div>

    {{-- Count badge --}}
    <span style="
        background: {{ $dotColor }}18;
        color: {{ $dotColor }};
        border: 1px solid {{ $dotColor }}44;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        padding: 1px 8px;
        min-width: 22px;
        text-align: center;
    ">
        {{ $count }}
    </span>
</div>
