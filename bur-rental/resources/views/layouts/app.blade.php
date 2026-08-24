<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'БУР — прокат будівельного інструменту')</title>
    <meta name="description" content="@yield('description', 'Подобова оренда будівельного, садового та вимірювального інструменту. Реальна наявність по датах і філіях.')">

    <link rel="canonical" href="{{ url()->current() }}">

    @if (config('app.noindex'))
        <meta name="robots" content="noindex, nofollow">
    @endif

    {{--
        Шрифти: Oswald (дисплей), Golos Text (текст), JetBrains Mono (числа) —
        свої, з @font-face у app.css. Preload тільки на кирилицю Golos Text:
        це основний текстовий шрифт, ним набрано майже все на першому екрані.
        Решту підмножин браузер витягне сам за unicode-range — вантажити їх
        наперед означало б відбирати смугу в того єдиного файлу, від якого
        залежить LCP.
    --}}
    <link rel="preload" as="font" type="font/woff2" crossorigin
          href="{{ Vite::asset('resources/fonts/golos-text-cyrillic.woff2') }}">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
{{-- padding-bottom тримає місце під нижню навігацію, щоб вона не накривала контент --}}
<body class="pb-[88px] nav:pb-0" x-data x-init="$store.booking.init()">

<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:z-100 focus:m-2 focus:rounded-[6px] focus:bg-brand focus:px-4 focus:py-2 focus:text-white">
    До змісту
</a>

@hasSection('promo')
    <div class="bg-brand-tint px-4 py-[9px] text-center text-[13px] font-semibold text-brand-hover">
        @yield('promo')
    </div>
@else
    <div class="bg-brand-tint px-4 py-[9px] text-center text-[13px] font-semibold text-brand-hover">
        Забронюйте сьогодні до 18:00 — заберете завтра з 8:00 · {{ $city->delivery_note }}
    </div>
@endif

<x-site-header />

<main id="main">
    @yield('content')
</main>

<x-site-footer />

<x-bottom-nav />
<x-cart-drawer />
<x-callback-modal />
<x-toasts />

</body>
</html>
