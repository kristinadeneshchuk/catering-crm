{{-- Індивідуальні страви прийому — формат 11.10: зліва складові, колонки — клієнти (спершу 🌙 вечірні), у клітинці грами, заміни червоним. --}}
<div style="margin:6px 0 18px; padding:8px 10px; border:2px solid #7c3aed; border-radius:8px; background:#faf5ff; -webkit-print-color-adjust:exact; print-color-adjust:exact; color:#111827;">
    <div style="font-size:13px; font-weight:900; color:#5b21b6; text-transform:uppercase; margin-bottom:6px;">{{ $block['meal'] }} · індивідуальні</div>
    <div style="display:flex; flex-wrap:wrap; gap:8px;">
        @foreach($block['dishes'] as $d)
            <div style="flex:1 1 300px; min-width:260px; page-break-inside:avoid; break-inside:avoid;">
                <table style="width:100%; border-collapse:collapse; font-size:11px; background:#fff; color:#111827;">
                    <thead>
                        <tr>
                            <th style="border:1px solid #9ca3af; padding:3px 5px; text-align:left; background:#ede9fe; color:#111827;">{{ $d['dish_name'] }}</th>
                            @foreach($d['clients'] as $c)
                                <th style="border:1px solid #9ca3af; padding:3px 5px; background:#ede9fe; color:#111827; font-size:10px;">{{ $c['label'] }}<br><span style="font-weight:500;">{{ $c['weight'] }} г</span></th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($d['rows'] as $row)
                            <tr>
                                <td style="border:1px solid #d1d5db; padding:2px 5px; font-weight:700; {{ $row['changed'] ? 'color:#dc2626;' : '' }}">{{ $row['name'] }}</td>
                                @foreach($d['clients'] as $i => $c)
                                    @php $g = $row['cells'][$i] ?? null; @endphp
                                    <td style="border:1px solid #d1d5db; padding:2px 5px; text-align:center; font-weight:800; {{ $row['changed'] && $g !== null ? 'color:#dc2626;' : '' }}">
                                        @if($g === null) — @elseif($g == 0) НЕ класти @else {{ $g }} г @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @foreach($d['notes'] as $n)
                    <div style="font-size:10px; color:#dc2626; font-weight:700; margin-top:2px;">• {{ $n['client'] }}: {{ $n['text'] }}</div>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
