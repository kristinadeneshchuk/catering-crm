{{-- Шапка колонки індивідуального клієнта: № ім'я, 🌙/☀ час · вага порції. --}}
<span style="display:inline-block; background:#111; color:#fff; border-radius:50%; min-width:17px; height:17px; line-height:17px; font-size:10px; text-align:center;">{{ $c['n'] }}</span>
{{ $c['name'] }}
<div style="font-weight:500; font-size:9.5px;">{{ $c['eve'] ? '🌙' : '☀' }} {{ $c['time'] }} · <b>{{ $c['weight'] }} г</b></div>
