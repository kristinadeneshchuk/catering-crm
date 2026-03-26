@php
    $statusColors = [
        'new'       => '#3b82f6',
        'no_answer' => '#eab308',
        'thinking'  => '#f97316',
        'refused'   => '#ef4444',
        'success'   => '#22c55e',
    ];

    $statusId  = $status['id'] ?? '';
    $tintColor = $statusColors[$statusId] ?? '#6b7280';
@endphp

<div style="
    width: 22rem;
    flex-shrink: 0;
    background: {{ $tintColor }}08;
    border-radius: 12px;
    padding: 10px;
    display: flex;
    flex-direction: column;
    min-height: 100px;
">
    {{-- Column header --}}
    @include(static::$headerView)

    {{-- Drop zone: required data attribute for drag-drop --}}
    <div
        data-status-id="{{ $status['id'] }}"
        style="
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-height: 60px;
        "
    >
        @foreach($status['records'] as $record)
            @include(static::$recordView)
        @endforeach
    </div>
</div>
