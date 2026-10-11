{{-- Готування індивідуальних: перший аркуш — список, далі кожен клієнт на окремому аркуші (стос).
     Лише власні страви клієнта («Інд · …»); страви циклу й довідника — у загальній партії вище. --}}
@php
    $pages = collect($pages ?? [])->filter(fn ($p) => count($p['own']) > 0)->values();
    $dayLabel = $dayLabel ?? '';
    $mealLabel = fn ($m) => str_contains(mb_strtolower($m), 'додаток') ? 'ДОДАТКОВО' : mb_strtoupper($m);
    $deliv = fn ($p) => ($p['eve'] ? '🌙 ВЕЧІРНЯ ' : '☀ РАНКОВА ').$p['time'].($p['eve'] ? ' · до 17:30' : '');
@endphp
@if($pages->isNotEmpty())
<div style="page-break-before:always; break-before:page; color:#111827; font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
    <div style="font-size:20px; font-weight:900;">Готування індивідуальних · раціон на {{ $dayLabel }}</div>
    <div style="font-size:11px; color:#555; margin:2px 0 8px;">ВЛАСНІ страви клієнтів і циклові з їхніми замінами; решта готується загальною партією. Далі — по клієнту на сторінку (стос). Спочатку 🌙 вечірні (пакувати до 17:30).</div>
    <table style="width:100%; border-collapse:collapse; font-size:12px;">
        <tr style="color:#666; font-size:11px;"><th style="text-align:left; border-bottom:1px solid #999; padding:3px;">Клієнт</th><th style="text-align:left; border-bottom:1px solid #999;">Доставка</th><th style="border-bottom:1px solid #999;">Страв готувати</th><th style="border-bottom:1px solid #999;">Готово</th></tr>
        @foreach($pages as $p)
            <tr>
                <td style="padding:4px 3px; border-bottom:1px solid #e5e7eb; font-weight:700;">{{ $p['n'] }}. {{ $p['name'] }} · {{ $p['kcal'] }}</td>
                <td style="border-bottom:1px solid #e5e7eb;">{{ $p['eve'] ? '🌙 вечірня' : '☀ ранкова' }} {{ $p['time'] }}</td>
                <td style="border-bottom:1px solid #e5e7eb; text-align:center; font-weight:800;">{{ count($p['own']) }}</td>
                <td style="border-bottom:1px solid #e5e7eb; text-align:center;">☐</td>
            </tr>
        @endforeach
    </table>
</div>

@foreach($pages as $p)
<div style="page-break-before:always; break-before:page; color:#111827; font-family:-apple-system,Segoe UI,Roboto,sans-serif; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
    <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:3px solid #14532d; padding-bottom:3px;">
        <div style="font-size:22px; font-weight:900; color:#14532d;">{{ $p['n'] }}. {{ $p['name'] }} <span style="font-size:13px; color:#555;">· {{ $p['kcal'] }} ккал</span></div>
        <div style="font-size:12px; font-weight:800; padding:3px 10px; border-radius:6px; background:{{ $p['eve'] ? '#1e3a8a' : '#fde68a' }}; color:{{ $p['eve'] ? '#fff' : '#78350f' }};">{{ $deliv($p) }}</div>
    </div>
    <div style="font-size:11px; color:#555; margin:3px 0 6px;">Готування індивідуальних · раціон на {{ $dayLabel }}</div>
    @if($p['stop'] || $p['diet'])
        <div style="background:#fde2e2; color:#9b1c1c; font-weight:700; font-size:12px; padding:4px 8px; border-radius:4px; margin-bottom:4px;">⛔ @if($p['diet'])⚕ {{ $p['diet'] }}. @endif{{ $p['stop'] }}</div>
    @endif
    @if($p['rules'] || $p['memo'])
        <div style="background:#e9f7ec; color:#14532d; font-size:12px; padding:4px 8px; border-radius:4px; margin-bottom:6px;">👨‍🍳 {{ trim($p['rules'].' '.$p['memo']) }}</div>
    @endif

    @foreach($p['own'] as $d)
        <div style="border:1px solid #9ca3af; border-radius:6px; padding:5px 8px; margin-bottom:6px; break-inside:avoid; page-break-inside:avoid;">
            <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:800;">
                <div><span style="color:#6b7280;">{{ $mealLabel($d['meal']) }}</span> {{ $d['dish_name'] }}@if(!empty($d['with_swaps'])) <span style="font-size:10.5px; color:#9b1c1c;">цикл · із замінами клієнта</span>@endif</div>
                <div>≈{{ $d['weight'] }} г готової</div>
            </div>
            <table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:2px;">
                <tr style="color:#6b7280; font-size:10.5px;"><td style="border-bottom:1px solid #d1d5db;">Продукт</td><td style="border-bottom:1px solid #d1d5db; text-align:right; width:70px;">нетто, г</td><td style="border-bottom:1px solid #d1d5db; text-align:right; width:90px;">зі складу, г</td></tr>
                @foreach($d['components'] as $comp)
                    @if(($comp['type'] ?? '') === 'pf')
                        <tr><td colspan="3" style="font-style:italic; color:#6b7280; font-size:11px; padding-top:2px;">НФ: {{ $comp['name'] }} ({{ round($comp['weight_output'] ?? 0) }} г)</td></tr>
                        @foreach($comp['sub_ingredients'] ?? [] as $sub)
                            @include('print.partials.ind-cook-row', ['row' => $sub, 'indent' => true])
                        @endforeach
                    @else
                        @include('print.partials.ind-cook-row', ['row' => $comp, 'indent' => false])
                    @endif
                @endforeach
            </table>
            @if(!empty($d['tech']))
                <div style="font-size:10.5px; line-height:1.35; margin-top:3px;">@foreach($d['tech'] as $step)<div>{{ $step }}</div>@endforeach</div>
            @endif
            @if(!empty($d['pack']))
                <div style="font-size:11.5px; font-weight:800; color:#14532d; margin-top:2px;">📦 {{ $d['pack'] }}</div>
            @endif
        </div>
    @endforeach

    @if(!empty($p['batch']))
        <div style="background:#e8f0fe; color:#1e3a8a; font-size:11.5px; padding:4px 8px; border-radius:4px; margin-bottom:6px;">З загальної партії (тут не готуємо): {{ implode('; ', $p['batch']) }}</div>
    @endif
    <div style="font-size:12px;">☐ усе приготовано &nbsp; ☐ зважено &nbsp; ☐ передано на пакування</div>
</div>
@endforeach
@endif
