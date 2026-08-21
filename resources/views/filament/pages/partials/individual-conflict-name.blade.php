{{-- Рядок інгредієнта в картці клієнта на індивідуальному меню.
     Виключення з анкети малюємо так само, як у картках циклічного меню:
     без позначки кухня готувала за звичайною рецептурою. --}}
@php($c = $conflict ?? null)

@if(!$c)
    {{ $name }}
@elseif(!empty($c['is_force_approved']))
    {{ $name }}
    <span style="color:#a16207; font-weight:800; font-size:10px;">⚡ СХВАЛЕНО</span>
@elseif(!empty($c['replacement']['name']))
    <span style="text-decoration:line-through; color:#9ca3af;">{{ $name }}</span>
    <span style="color:#15803d; font-weight:800;">→ {{ $c['replacement']['name'] }}</span>
@else
    <span style="text-decoration:line-through; color:#9ca3af;">{{ $name }}</span>
    <span style="color:#b91c1c; font-weight:800; font-size:10px;">БЕЗ ЦЬОГО</span>
    @if(!empty($c['bundle_suggestion']['name']))
        <span style="color:#1d4ed8; font-size:10px;">(шаблон: {{ $c['bundle_suggestion']['name'] }})</span>
    @endif
@endif
