<!doctype html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'БУР — прокат будівельного інструменту')</title>
    <meta name="description" content="@yield('description', 'Подобова оренда будівельного, садового та вимірювального інструменту. Реальна наявність по датах і філіях.')">

    <link rel="canonical" href="{{ url()->current() }}">

    @php
        /*
        | Сторінки, яким нема чого робити в індексі. Список тут, а не в кожному
        | шаблоні: додали маршрут — не забули закрити.
        |
        | Пошук найважливіший: без noindex він плодить нескінченні тонкі
        | сторінки під кожен запит, і саме так сайт заробляє репутацію
        | неякісного. robots.txt для цього не годиться — заборона обходу лише
        | не дає роботу прочитати сторінку, але не прибирає її з видачі.
        */
        $noindex = config('app.noindex') || request()->routeIs(
            'search', 'favourites', 'booking.create', 'booking.show',
            'cabinet', 'cabinet.*',
        );
    @endphp

    @if ($noindex)
        <meta name="robots" content="noindex, follow">
    @endif

    {{--
        Прев'ю посилання. Основний канал, яким в Україні передають контакт
        підрядника, — це месенджери; без цих тегів посилання приходить голим
        рядком. og:image з'явиться разом із реальними фото.
    --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="БУР">
    <meta property="og:locale" content="uk_UA">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="@yield('title', 'БУР — прокат будівельного інструменту')">
    <meta property="og:description" content="@yield('description', 'Подобова оренда будівельного, садового та вимірювального інструменту. Реальна наявність по датах і філіях.')">
    <meta name="twitter:card" content="summary">

    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    <meta name="theme-color" content="#0e5b46">

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

    @php
        // Стартовий стан обраного. У гостя список порожній — його підставить
        // localStorage; у залогіненого приходить із сервера, бо він мусить бути
        // однаковий на всіх пристроях.
        $client = auth('client')->user();
        $favourites = json_encode([
            'authenticated' => (bool) $client,
            'ids' => $client ? $client->favourites()->pluck('products.id') : [],
        ], JSON_UNESCAPED_UNICODE);
    @endphp
    <script>window.burFavourites = {!! $favourites !!};</script>

    <x-schema-site />

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
