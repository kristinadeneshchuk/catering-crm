@extends('layouts.app')

@section('title', 'Оренда '.($category->name_genitive ?? $category->name).' '.$city->name_locative.' — БУР')
@section('description', Str::limit($category->lead, 155))

@section('content')
    <div class="container-bur"
         x-data="filters({
            applied: {{ Js::from(request()->only(['brand', 'branch', 'free'])) }},
            total: {{ $products->total() }},
         })">

        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            'Каталог' => route('catalog.index'),
            $category->name => null,
        ]" />

        <h1 class="t-h1">Оренда {{ $category->name_genitive ?? $category->name }} {{ $city->name_locative }}</h1>
        <p class="mt-2 max-w-[760px] text-[15px] leading-[26px] text-text-2">{{ $category->lead }}</p>

        @if ($category->children->isNotEmpty())
            <div class="mt-5 flex flex-wrap gap-2">
                @foreach ($category->children as $child)
                    <a href="{{ route('category', $child) }}"
                       class="inline-flex min-h-11 items-center gap-2 rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm text-text-1 no-underline hover:border-brand hover:no-underline">
                        {{ $child->name }}
                        <span class="font-mono text-xs text-text-3">{{ $child->products_count }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="mt-6 grid gap-6 lg:[grid-template-columns:280px_1fr]">

            {{-- ——— Фільтри ——— --}}
            <aside class="lg:sticky lg:top-[88px] lg:self-start max-lg:contents">
                <button type="button" @click="open = true"
                        class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-[6px] border border-border-1 bg-surface-0 text-sm font-semibold lg:hidden">
                    <x-ui-icon name="sliders" class="size-4" />
                    Фільтри
                    <span x-show="appliedChips.length" x-text="appliedChips.length"
                          class="rounded-[2px] bg-brand px-1.5 font-mono text-[11px] text-white"></span>
                </button>

                {{--
                    На вузькому вікні панель виїжджає окремим шаром, на мобільному — bottom sheet.
                    Видимість на desktop тримає CSS, а не x-show: інакше фільтри блимають,
                    поки не завантажився Alpine.
                --}}
                <div :class="open ? 'max-lg:fixed max-lg:inset-0 max-lg:z-100 max-lg:bg-surface-dark/45' : 'max-lg:hidden'"
                     class="max-lg:hidden lg:block"
                     @click="open = false">
                    <form @submit.prevent="apply()" @click.stop method="get"
                          class="rounded-[12px] border border-border-1 bg-surface-0 p-4 max-lg:absolute max-lg:inset-x-0 max-lg:bottom-0 max-lg:max-h-[85vh] max-lg:overflow-auto max-lg:rounded-b-none">

                        <div class="mb-4 flex items-center justify-between lg:hidden">
                            <h2 class="t-h2">Фільтри</h2>
                            <button type="button" @click="open = false" class="cursor-pointer p-2" aria-label="Закрити">
                                <x-ui-icon name="close" class="size-5" />
                            </button>
                        </div>

                        {{-- Дати вгорі: це найважливіший фільтр на прокаті --}}
                        <fieldset>
                            <legend class="text-[13px] font-semibold text-text-2">Дати оренди</legend>
                            <div class="mt-2 grid grid-cols-2 gap-2">
                                <input type="date" name="from" value="{{ $from }}"
                                       class="h-11 rounded-[6px] border border-border-1 px-2 font-mono text-[13px]">
                                <input type="date" name="to" value="{{ $to }}"
                                       class="h-11 rounded-[6px] border border-border-1 px-2 font-mono text-[13px]">
                            </div>
                            <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                <input type="checkbox" name="free" value="1" @checked(request()->boolean('free'))
                                       class="size-4 accent-[var(--color-brand)]">
                                Тільки вільні на обрані дати
                            </label>
                        </fieldset>

                        <fieldset class="mt-5 border-t border-surface-2 pt-4">
                            <legend class="text-[13px] font-semibold text-text-2">Філія</legend>
                            <label class="mt-2 flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                <input type="radio" name="branch" value="" @checked(! $activeBranch) class="accent-[var(--color-brand)]">
                                Усі філії {{ $city->name }}
                            </label>
                            @foreach ($branches as $branch)
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <input type="radio" name="branch" value="{{ $branch->slug }}"
                                           @checked($activeBranch?->id === $branch->id) class="accent-[var(--color-brand)]">
                                    {{ $branch->name }}
                                    <span class="ml-auto font-mono text-xs text-text-3">{{ number_format((float) $branch->distance_km, 1, ',', ' ') }} км</span>
                                </label>
                            @endforeach
                        </fieldset>

                        <fieldset class="mt-5 border-t border-surface-2 pt-4">
                            <legend class="text-[13px] font-semibold text-text-2">Бренд</legend>
                            @foreach ($brands as $brand)
                                <label class="flex min-h-11 cursor-pointer items-center gap-2 text-sm">
                                    <input type="checkbox" name="brand[]" value="{{ $brand->slug }}"
                                           @checked(in_array($brand->slug, (array) request('brand'), true))
                                           class="size-4 accent-[var(--color-brand)]">
                                    {{ $brand->name }}
                                    <span class="ml-auto font-mono text-xs text-text-3">{{ $brand->products_count }}</span>
                                </label>
                            @endforeach
                        </fieldset>

                        @if ($category->filter_specs)
                            <fieldset class="mt-5 border-t border-surface-2 pt-4">
                                <legend class="text-[13px] font-semibold text-text-2">Ключові параметри</legend>
                                <p class="mt-1 text-xs text-text-3">{{ implode(' · ', $category->filter_specs) }}</p>
                            </fieldset>
                        @endif

                        <div class="mt-5 flex gap-2 border-t border-surface-2 pt-4">
                            <button type="submit" class="h-11 flex-1 cursor-pointer rounded-[6px] bg-brand text-sm font-semibold text-white">
                                Показати {{ $products->total() }}
                            </button>
                            <a href="{{ route('category', $category) }}"
                               class="flex h-11 items-center rounded-[6px] border border-border-1 px-4 text-sm text-text-2 no-underline">
                                Скинути
                            </a>
                        </div>
                    </form>
                </div>
            </aside>

            {{-- ——— Лістинг ——— --}}
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <span class="text-sm text-text-2">Знайдено: <span class="font-mono font-semibold">{{ $products->total() }}</span></span>

                    <form method="get" class="ml-auto flex items-center gap-2 text-sm">
                        @foreach (request()->except(['sort', 'page']) as $key => $value)
                            @foreach ((array) $value as $item)
                                <input type="hidden" name="{{ $key }}{{ is_array($value) ? '[]' : '' }}" value="{{ $item }}">
                            @endforeach
                        @endforeach
                        <label for="sort" class="text-text-3">Сортування:</label>
                        <select id="sort" name="sort" onchange="this.form.submit()"
                                class="h-11 rounded-[6px] border border-border-1 bg-surface-0 px-2 text-sm">
                            @foreach (['popular' => 'за релевантністю', 'price-asc' => 'ціна ↑', 'price-desc' => 'ціна ↓', 'weight' => 'за вагою'] as $value => $label)
                                <option value="{{ $value }}" @selected(request('sort') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                {{-- Активні фільтри чипсами --}}
                @php
                    $chips = collect((array) request('brand'))->map(fn ($slug) => ['label' => $brands->firstWhere('slug', $slug)?->name, 'key' => 'brand', 'value' => $slug])
                        ->when($activeBranch, fn ($c) => $c->push(['label' => 'Філія: '.$activeBranch->name, 'key' => 'branch', 'value' => $activeBranch->slug]))
                        ->when(request()->boolean('free'), fn ($c) => $c->push(['label' => 'Тільки вільні', 'key' => 'free', 'value' => '1']))
                        ->filter(fn ($chip) => $chip['label']);
                @endphp

                @if ($chips->isNotEmpty())
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @foreach ($chips as $chip)
                            @php
                                $params = request()->except('page');
                                if ($chip['key'] === 'brand') {
                                    $params['brand'] = array_values(array_diff((array) request('brand'), [$chip['value']]));
                                } else {
                                    unset($params[$chip['key']]);
                                }
                            @endphp
                            <a href="{{ route('category', $category).'?'.http_build_query($params) }}"
                               class="inline-flex items-center gap-2 rounded-[2px] border border-border-1 bg-surface-0 py-1 pl-3 pr-1.5 text-[13px] text-text-1 no-underline hover:no-underline">
                                {{ $chip['label'] }}
                                <span class="inline-flex size-[22px] items-center justify-center rounded-[2px] text-text-3 hover:bg-surface-1" aria-label="Прибрати фільтр">
                                    <x-ui-icon name="close" class="size-3" />
                                </span>
                            </a>
                        @endforeach

                        <a href="{{ route('category', $category) }}" class="text-[13px] text-text-3 underline">скинути все</a>
                    </div>
                @endif

                @if ($products->isEmpty())
                    {{-- Порожній стан пропонує дію, а не повідомляє про невдачу --}}
                    <div class="mt-6 rounded-[12px] border border-border-1 bg-surface-0 p-8 text-center">
                        <h2 class="t-h3">На ці дати за такими фільтрами нічого немає</h2>
                        <p class="mx-auto mt-2 max-w-[420px] text-sm text-text-2">
                            Приберіть бренд або вимкніть «тільки вільні» — покажемо, що звільниться найближчим часом.
                        </p>
                        <a href="{{ route('category', $category) }}"
                           class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:text-white">
                            Скинути фільтри
                        </a>
                    </div>
                @else
                    <div class="mt-4 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
                        @foreach ($products as $product)
                            <x-product-card :product="$product" :branches="$branches" :from="$from" :to="$to" />
                        @endforeach
                    </div>

                    {{-- Пагінація для краулера + «показати ще» для людей --}}
                    <div class="mt-6">{{ $products->links() }}</div>
                @endif
            </div>
        </div>

        <x-rent-vs-buy />
        <x-faq-list :faqs="$category->faqs" title="Питання по категорії" />
        <x-seo-text :title="'Оренда '.($category->name_genitive ?? $category->name).' '.$city->name_locative.' — як обрати і скільки коштує'" :body="$category->seo_text" />
        <x-district-links :city="$city" :title="'Оренда '.($category->name_genitive ?? 'інструменту').' у районах '.$city->name" />
    </div>
@endsection

@push('head')
    {{--
        Лістинг як список товарів, а не як суцільний текст: робот бачить, що
        саме на сторінці, і в якому порядку.
    --}}
    @php
        $listSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $category->name,
            'numberOfItems' => $products->total(),
            'itemListElement' => $products->values()->map(fn ($p, $i) => [
                '@type' => 'ListItem',
                'position' => $products->firstItem() + $i,
                'url' => route('product', $p),
                'name' => $p->brand->name.' '.$p->name,
            ])->all(),
        ];
    @endphp

    <script type="application/ld+json">{!! json_encode($listSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
