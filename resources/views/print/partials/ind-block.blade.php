{{-- Індивідуальні страви прийому — стандарт 11.10: таблиця на страву, складові × клієнти, «Разом»; ≈ — власна страва. --}}
@php
    $mc = ['сніданок'=>'#14b8a6','перекус'=>'#84cc16','обід'=>'#fb923c','полуденок'=>'#f472b6','вечеря'=>'#38bdf8'];
    $color = '#94a3b8';
    foreach ($mc as $k => $v) { if (str_contains(mb_strtolower($block['meal']), $k)) { $color = $v; break; } }
@endphp
<div style="display:flex; flex-wrap:wrap; gap:8px; margin:4px 0 16px; color:#111827; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
    @foreach(collect($block['dishes'])->sortByDesc(fn($d) => count($d['clients'])) as $d)
        @php $approx = !$d['is_cycle'] && !str_contains(mb_strtolower($block['meal']), 'додаток'); @endphp
        <div class="ind-card" data-wide="{{ count($d['clients']) >= 3 ? 1 : 0 }}" style="flex:{{ count($d['clients']) >= 3 ? '1 1 100%' : '1 1 30%' }}; min-width:250px; break-inside:avoid; page-break-inside:avoid;">
            <div style="background:{{ $color }}; color:#fff; font-weight:800; padding:4px 8px; border-radius:4px 4px 0 0; font-size:12px; text-shadow:0 1px 1px rgba(0,0,0,.3);">
                {{ $block['meal'] }}: {{ $d['dish_name'] }}@if($d['is_cycle']) <span style="font-size:9.5px; opacity:.9;">цикл</span>@endif · порцій: {{ count($d['clients']) }}
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:11px; background:#fff;">
                <tr>
                    <th style="border:1px solid #c9c9c9; padding:2px 4px; text-align:left; background:#fff; color:#111827;">Складова</th>
                    @foreach($d['clients'] as $c)
                        <th style="border:1px solid #c9c9c9; padding:2px 4px; font-size:10.5px; background:{{ $c['eve'] ? '#1e3a8a' : '#fde68a' }}; color:{{ $c['eve'] ? '#fff' : '#78350f' }};">@include('print.partials.ind-colhead', ['c' => $c])</th>
                    @endforeach
                    <th style="border:1px solid #c9c9c9; padding:2px 4px; background:#e5e7eb; color:#111827; width:52px;">Разом</th>
                </tr>
                @foreach($d['rows'] as $row)
                    @php $tot = 0; @endphp
                    <tr>
                        <td style="border:1px solid #c9c9c9; padding:2px 4px; font-weight:700; text-align:left; background:#fff;">{{ $row['name'] }}</td>
                        @foreach($d['clients'] as $i => $c)
                            @php $cell = $row['cells'][$i] ?? null; if ($cell && empty($cell['skip'])) $tot += $cell['g']; @endphp
                            <td style="border:1px solid #c9c9c9; padding:2px 4px; text-align:center; background:{{ $cell && (!empty($cell['skip']) || !empty($cell['note'])) ? '#fee2e2' : '#f3f4f6' }};">
                                @if($cell) @include('print.partials.ind-cell', ['cell' => $cell, 'approx' => $approx, 'noUnit' => true]) @else — @endif
                            </td>
                        @endforeach
                        <td style="border:1px solid #c9c9c9; padding:2px 4px; text-align:center; font-weight:800; background:#e5e7eb;">{{ round($tot) }}</td>
                    </tr>
                @endforeach
            </table>
            @include('print.partials.ind-pack', ['pack' => $d['pack']])
        </div>
    @endforeach
</div>
