{{-- Реєстр індивідуальних (стандарт 11.10): №, клієнт, доставка, ⛔ не можна. Вечірні першими — пакувати до 17:30. --}}
@if(!empty($registry))
<div style="margin:10px 0 14px; -webkit-print-color-adjust:exact; print-color-adjust:exact; color:#111827;">
    <div style="font-size:14px; font-weight:900; margin-bottom:3px;">Індивідуальні · реєстр клієнтів</div>
    <div style="font-size:10px; color:#444; margin-bottom:4px;">У колонках таблиць — № клієнта (спочатку 🌙 вечірні — пакувати до 17:30). <b style="color:#9b1c1c">Червона клітинка</b> — заміна або «не класти». ≈ — власна страва клієнта, вага розподілена за техкартою.</div>
    <table style="width:100%; border-collapse:collapse; font-size:11px;">
        <tr>
            @foreach(['№','Клієнт','Доставка','⛔ Не можна'] as $h)
                <th style="border:1px solid #c9c9c9; padding:3px 5px; background:#e5e7eb; text-align:left; color:#111827;">{{ $h }}</th>
            @endforeach
        </tr>
        @foreach($registry as $r)
            <tr style="background:{{ $r['eve'] ? '#e0e7ff' : '#ffffff' }};">
                <td style="border:1px solid #c9c9c9; padding:3px 5px; font-weight:800; text-align:center; width:26px;">{{ $r['n'] }}</td>
                <td style="border:1px solid #c9c9c9; padding:3px 5px; font-weight:700;">{{ $r['name'] }}</td>
                <td style="border:1px solid #c9c9c9; padding:3px 5px; white-space:nowrap;">{{ $r['eve'] ? '🌙 ВЕЧІРНЯ' : '☀ ранкова' }} {{ $r['time'] }}</td>
                <td style="border:1px solid #c9c9c9; padding:3px 5px; color:#9b1c1c; font-size:10px;">
                    @if(!empty($r['diet']))<b style="background:#dc2626; color:#fff; padding:0 5px; border-radius:3px;">⚕ {{ $r['diet'] }}</b> @endif{{ $r['stop'] }}
                    @if(!empty($showDiet) && !empty($r['diet_rules']))<div style="color:#7f1d1d; font-size:10.5px; margin-top:2px;">{{ $r['diet_rules'] }}</div>@endif
                </td>
            </tr>
        @endforeach
    </table>
</div>
@endif
