{{-- Очікуване списання за нормою — ті самі цифри, що спише нічний stock:debit-norm. Без цін. --}}
@if(!empty($writeoff['lines']) || !empty($writeoff['skipped']))
    @php
        $fmtQty = fn (float $q, string $u) => in_array(mb_strtolower($u), ['шт', 'уп', 'пач'], true)
            ? number_format($q, 0, ',', ' ')
            : rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');
        $groups = collect($writeoff['lines'])->groupBy('kind');
        $days = collect($writeoff['food_dates'])->map(fn ($d) => \Carbon\Carbon::parse($d)->locale('uk')->translatedFormat('D d.m'))->implode(', ');
    @endphp
    <div style="border:2px solid #0f766e;border-radius:8px;padding:10px 12px;margin-bottom:20px;background:#f0fdfa;color:#134e4a;">
        <div style="font-weight:900;text-transform:uppercase;font-size:13px;margin-bottom:6px;">
            Очікуване списання <span style="font-weight:600;text-transform:none;">— їжа на {{ $days }}</span>
        </div>
        @foreach(['ingredient' => 'Продукти', 'packaging' => 'Упаковка'] as $kind => $title)
            @if($groups->has($kind))
                <div style="font-weight:800;font-size:11px;text-transform:uppercase;margin:6px 0 3px;opacity:.8;">{{ $title }} ({{ $groups[$kind]->count() }})</div>
                <div style="columns:3 220px;column-gap:24px;font-size:12px;">
                    @foreach($groups[$kind] as $l)
                        <div style="break-inside:avoid;display:flex;justify-content:space-between;gap:8px;border-bottom:1px dotted #99f6e4;padding:1px 0;">
                            <span>{{ $l['name'] }}</span><b style="white-space:nowrap;">{{ $fmtQty($l['qty'], $l['unit']) }} {{ $l['unit'] }}</b>
                        </div>
                    @endforeach
                </div>
            @endif
        @endforeach
        @if(!empty($writeoff['skipped']))
            <div style="font-size:11px;margin-top:6px;color:#b45309;">
                Не перераховано в одиниці складу (перевірити картку): 
                @foreach($writeoff['skipped'] as $s){{ $s['name'] }} — {{ round($s['grams']) }} г@if(!$loop->last); @endif @endforeach
            </div>
        @endif
    </div>
@endif
