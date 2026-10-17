<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Готування індивідуальних {{ $dayLabel }}</title>
    <style>
        body { margin: 0; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        @media screen { body { background: #e5e7eb; } .ind-page, body > div { background: #fff; max-width: 190mm; margin: 0 auto 10px; padding: 8mm; } }
    </style>
</head>
<body>
@php $any = false; @endphp
@foreach($report as $planData)
    @if(collect($planData['ind_pages'] ?? [])->contains(fn ($p) => count($p['own']) > 0))
        @php $any = true; @endphp
        @include('print.partials.ind-cook-pages', ['pages' => $planData['ind_pages'], 'dayLabel' => $dayLabel])
    @endif
@endforeach
@unless($any)
    <p style="padding:20px; font-family:sans-serif;">На {{ $dayLabel }} власних страв індивідуальних клієнтів немає.</p>
@endunless
</body>
</html>
