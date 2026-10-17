{{-- Шапка колонки клієнта: № ім'я, 🌙/☀ час · вага порції (для звичайних клієнтів із замінами — без №). --}}
@if(!empty($c['n']))<span style="display:inline-block; background:#111; color:#fff; border-radius:50%; min-width:17px; height:17px; line-height:17px; font-size:10px; text-align:center;">{{ $c['n'] }}</span>@endif
{{ $c['name'] }}@if(!empty($c['brand'])) <b>({{ $c['brand'] }})</b>@endif
<div style="font-weight:500; font-size:9.5px;">@if(empty($c['cust'])){{ $c['eve'] ? '🌙' : '☀' }} {{ $c['time'] }} · @else{{ $c['eve'] ? '🌙' : '☀' }} @endif<b>{{ $c['weight'] }} г</b>@if(!empty($c['raw'])) <span title="Сума сирих продуктів за техкартою — не вага готового боксу">сирих</span>@endif</div>
