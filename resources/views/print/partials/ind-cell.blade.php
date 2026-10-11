{{-- Клітинка індивідуального клієнта: грами; заміна — червоним з приміткою; «НЕ класти». --}}
@if(!empty($cell['skip']))
    <span style="color:#9b1c1c; font-weight:800;">✖ НЕ класти</span>
@else
    <b style="{{ !empty($cell['note']) ? 'color:#9b1c1c;' : '' }}">{{ !empty($approx) ? '≈' : '' }}{{ round($cell['val'] ?? $cell['g'] ?? 0) }}@if(empty($noUnit)) г@endif</b>
    @if(!empty($cell['note']))<div style="font-size:9px; font-weight:700; color:#9b1c1c;">{{ $cell['note'] }}</div>@endif
@endif
