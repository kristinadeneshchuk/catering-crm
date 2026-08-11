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
        усі з повною українською кирилицею. Для продакшену їх варто self-host'ити
        через @font-face з font-display: swap і підмножиною кирилиці: шрифти
        лежать у критичному шляху LCP, а сторонній домен додає зайвий handshake.
        Див. bur-rental/README.md, розділ «Шрифти».
    --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Golos+Text:wght@400;500;600&family=JetBrains+Mono:wght@400;600;700&family=Oswald:wght@500;600;700&display=swap">

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
