@extends('layouts.app')

@section('title', 'БУР — прокат будівельного інструменту '.$city->name_locative)
@section('description', 'Подобова оренда інструменту '.$city->name_locative.'. Реальна наявність по датах і філіях, застава повертається, доставка щодня.')

@section('content')
    <div class="container-bur">

        {{--
            Hero — не карусель. LCP-елемент тут текст і поля пошуку: вони
            рендеряться миттєво, тоді як карусель тягне за собою картинки і JS.
        --}}
        <section class="mt-6 rounded-[12px] border border-border-1 bg-surface-0 p-6 md:p-10">
            <h1 class="t-display max-w-[720px]">Прокат інструменту {{ $city->name_locative }}</h1>
            <p class="mt-3 max-w-[640px] text-[18px] leading-[28px] text-text-2">
                Подобово, із заставою, що повертається. Наявність по датах і філіях видно на сайті —
                без дзвінків «а є?».
            </p>

            <form action="{{ route('search') }}" method="get" class="mt-6 grid gap-3 md:[grid-template-columns:1fr_160px_160px_auto]">
                <div>
                    <label for="hero-q" class="mb-1 block text-[13px] font-medium text-text-2">Що потрібно</label>
                    <input id="hero-q" name="q" type="search" placeholder="Перфоратор, віброплита, «кладу плитку»…"
                           class="h-13 w-full rounded-[6px] border border-border-1 px-3 text-[15px] outline-none focus:border-brand">
                </div>
                <div>
                    <label for="hero-from" class="mb-1 block text-[13px] font-medium text-text-2">З</label>
                    <input id="hero-from" name="from" type="date" value="{{ now()->toDateString() }}"
                           class="h-13 w-full rounded-[6px] border border-border-1 px-3 font-mono text-sm outline-none focus:border-brand">
                </div>
                <div>
                    <label for="hero-to" class="mb-1 block text-[13px] font-medium text-text-2">По</label>
                    <input id="hero-to" name="to" type="date" value="{{ now()->addDays(4)->toDateString() }}"
                           class="h-13 w-full rounded-[6px] border border-border-1 px-3 font-mono text-sm outline-none focus:border-brand">
                </div>
                <div class="flex items-end">
                    <button type="submit" class="h-13 w-full cursor-pointer rounded-[6px] bg-brand px-6 text-base font-semibold text-white hover:bg-brand-hover">
                        Знайти
                    </button>
                </div>
            </form>

            <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-[13px] text-text-2">
                <span>Філії: {{ $branches->pluck('name')->join(', ') }}</span>
                <span>{{ $city->delivery_note }}</span>
                <span class="font-mono font-semibold">{{ $city->phone }}</span>
            </div>
        </section>

        <x-section title="Популярні категорії">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
                @foreach ($categories as $category)
                    <a href="{{ route('category', $category) }}"
                       class="group overflow-hidden rounded-[12px] border border-border-1 bg-surface-0 no-underline hover:border-brand hover:no-underline">
                        <x-image-slot :label="$category->name" ratio="16/10" />
                        <div class="p-3.5">
                            <div class="text-sm font-semibold text-text-1">{{ $category->name }}</div>
                            <div class="mt-0.5 font-mono text-xs text-text-3">{{ $category->products_count }} позицій</div>
                        </div>
                    </a>
                @endforeach
            </div>
        </x-section>

        {{-- Вхід через задачу, а не через інструмент: половина клієнтів не знає назв --}}
        <x-section title="Що ви робите?" lead="Скажіть задачу — зберемо комплект, яким її роблять."
                   action="Усі комплекти" :action-url="route('kits.index')">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(220px,1fr))]">
                @foreach ($kits as $kit)
                    <a href="{{ route('kit', $kit) }}"
                       class="rounded-[12px] border border-border-1 bg-surface-0 p-5 no-underline hover:border-brand hover:no-underline">
                        <div class="t-h3 text-text-1">{{ $kit->name }}</div>
                        <p class="mt-1 text-[13px] text-text-3">{{ $kit->task }}</p>
                        <div class="mt-3 font-mono text-sm font-bold text-brand">
                            від {{ number_format($kit->priceFor(7), 0, ',', ' ') }} ₴ →
                        </div>
                    </a>
                @endforeach
            </div>
        </x-section>

        <x-section title="Як це працює">
            <ol class="grid gap-4 md:grid-cols-4">
                @foreach ([
                    'Обираєте дати' => 'Календар показує реальну зайнятість по кожній філії.',
                    'Бронюєте онлайн' => 'Підтверджуємо одразу, без «менеджер передзвонить уточнити».',
                    'Забираєте або привозимо' => 'Самовивіз щодня 8:00–20:00 або доставка на об\'єкт.',
                    'Повертаєте — застава назад' => 'Одразу при поверненні справного інструменту.',
                ] as $title => $text)
                    <li class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <div class="font-mono text-2xl font-bold text-brand">{{ $loop->iteration }}</div>
                        <div class="mt-1 text-[15px] font-semibold">{{ $title }}</div>
                        <p class="mt-1 text-[13px] leading-[20px] text-text-2">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </x-section>

        <x-section title="Тариф падає зі строком" lead="Одна модель, три ціни. Чим довше — тим дешевше за день.">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ([['1–2 дні', 350, 'базовий тариф'], ['3–6 днів', 290, '−17%'], ['від 7 днів', 240, '−31%']] as [$label, $price, $note])
                    <div class="rounded-[12px] border {{ $loop->last ? 'border-2 border-brand bg-brand-tint' : 'border-border-1 bg-surface-0' }} p-5">
                        <div class="text-sm font-medium text-text-2">{{ $label }}</div>
                        <div class="t-price mt-1">{{ $price }} ₴</div>
                        <div class="text-xs text-text-3">за день · приклад для перфоратора</div>
                        <div class="mt-2 inline-block rounded-[2px] px-1.5 py-px text-[11px] font-semibold {{ $loop->first ? 'text-text-3' : 'bg-success-bg text-success-text' }}">
                            {{ $note }}
                        </div>
                    </div>
                @endforeach
            </div>
        </x-section>

        <x-section title="Популярне {{ $city->name_locative }}" action="Увесь каталог" :action-url="route('catalog.index')">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                @foreach ($popular as $product)
                    <x-product-card :product="$product" :branches="$branches" />
                @endforeach
            </div>
        </x-section>

        <x-rent-vs-buy />

        <x-section title="Філії {{ $city->name_locative }}" action="Усі міста" :action-url="route('contacts')">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($branches as $branch)
                    <x-branch-card :branch="$branch" :city="$city" />
                @endforeach
            </div>
        </x-section>

        <x-reviews :reviews="$reviews" title="Відгуки з Google" :rating="4.8" />

        {{-- Блок для юросіб: 15% трафіку, але найбільший чек --}}
        <x-section>
            <div class="grid gap-6 rounded-[12px] bg-surface-dark p-8 text-text-on-dark md:grid-cols-[1fr_auto] md:items-center">
                <div>
                    <h2 class="t-h2 text-white">Юрособам і прорабам</h2>
                    <p class="mt-2 max-w-[560px] text-sm">
                        Безготівка, рахунок і акти того ж дня, відстрочка до 30 днів після третьої оренди,
                        застава не потрібна, персональний менеджер.
                    </p>
                </div>
                <a href="{{ route('b2b') }}"
                   class="inline-flex h-13 items-center justify-center rounded-[6px] bg-brand-bright px-6 text-base font-semibold text-surface-dark no-underline hover:bg-white hover:no-underline">
                    Умови для юросіб
                </a>
            </div>
        </x-section>

        <x-district-links :city="$city" />
    </div>
@endsection
