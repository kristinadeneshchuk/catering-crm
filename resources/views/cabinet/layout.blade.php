<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Кабінет — {{ $client->name }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">
@php
    $menuToken = $client->orders()->whereNotNull('menu_token')->orderByDesc('id')->value('menu_token');
    $tabs = [
        ['key' => 'overview',   'label' => 'Профіль',    'url' => route('cabinet.overview', $token)],
        ['key' => 'orders',     'label' => 'Замовлення',  'url' => route('cabinet.orders', $token)],
        ['key' => 'payments',   'label' => 'Оплати',      'url' => route('cabinet.payments', $token)],
        ['key' => 'deliveries', 'label' => 'Доставки',    'url' => route('cabinet.deliveries', $token)],
    ];
    if ($menuToken) {
        $tabs[] = ['key' => 'menu', 'label' => 'Меню та відгуки', 'url' => route('menu.show', $menuToken)];
    }
@endphp

<div class="max-w-3xl mx-auto px-4 pb-16">
    <header class="pt-6 pb-4">
        <div class="text-xs uppercase tracking-wider text-amber-600 font-semibold">Особистий кабінет</div>
        <h1 class="text-2xl font-bold">{{ $client->name }}</h1>
        <div class="text-sm text-slate-500">{{ $client->phone }}</div>
    </header>

    <nav class="flex gap-1 overflow-x-auto bg-white rounded-xl p-1 shadow-sm border border-slate-200 mb-5">
        @foreach($tabs as $t)
            <a href="{{ $t['url'] }}"
               class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium transition
                      {{ ($active ?? '') === $t['key'] ? 'bg-amber-500 text-white' : 'text-slate-600 hover:bg-slate-100' }}">
                {{ $t['label'] }}
            </a>
        @endforeach
    </nav>

    <main>
        @yield('content')
    </main>
</div>
</body>
</html>
