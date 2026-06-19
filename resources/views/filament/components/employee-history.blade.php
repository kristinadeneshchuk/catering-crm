@php
    $events = $employee->buildHistory();
@endphp

<div style="font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif;">

    {{-- Шапка з балансом --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:#111113;border:1px solid #27272a;border-radius:12px;margin-bottom:14px;">
        <div>
            <p style="margin:0;color:#71717a;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Поточний баланс</p>
            <p style="margin:4px 0 0;color:{{ $employee->balance > 0 ? '#34d399' : ($employee->balance < 0 ? '#f87171' : '#a1a1aa') }};font-size:24px;font-weight:900;line-height:1;">
                {{ number_format((float) $employee->balance, 2, '.', ' ') }} ₴
            </p>
        </div>
        <div style="text-align:right;color:#71717a;font-size:12px;">
            {{ $employee->positionData?->name ?? $employee->position }}
        </div>
    </div>

    @if(empty($events))
        <div style="text-align:center;padding:30px 20px;color:#52525b;font-size:13px;background:#111113;border:1px solid #27272a;border-radius:12px;">
            Подій ще немає.
        </div>
    @else
        <div style="background:#111113;border:1px solid #27272a;border-radius:12px;overflow:hidden;max-height:420px;overflow-y:auto;">
            @foreach($events as $ev)
                @php
                    $color = match($ev['kind']) {
                        'shift'   => '#f4f4f5',
                        'penalty' => '#f87171',
                        'comp'    => '#fbbf24',
                        'payout'  => '#60a5fa',
                        default   => '#a1a1aa',
                    };
                    $sign = $ev['amount'] >= 0 ? '+' : '−';
                    $amt  = number_format(abs((float) $ev['amount']), 2, '.', ' ');
                    $dateStr = $ev['date'] instanceof \Carbon\Carbon
                        ? $ev['date']->format('d.m.Y')
                        : \Carbon\Carbon::parse($ev['date'])->format('d.m.Y');
                @endphp
                <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 16px;border-bottom:1px solid #1f1f22;">
                    <div style="flex:1;">
                        <div style="color:#f4f4f5;font-size:13px;">{{ $ev['label'] }}</div>
                        <div style="color:#52525b;font-size:11px;margin-top:2px;">{{ $dateStr }}</div>
                    </div>
                    <div style="color:{{ $color }};font-weight:700;font-size:14px;">
                        {{ $sign }}{{ $amt }} ₴
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
