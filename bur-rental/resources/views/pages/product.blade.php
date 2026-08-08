@extends('layouts.app')

@section('title', 'Оренда: '.$product->brand->name.' '.$product->name.' — '.$city->name_locative)
@section('description', Str::limit(strip_tags($product->lead), 155))

@section('content')
    @php
        $tiers = $product->tiers->map(fn ($t) => [
            'label' => $t->label, 'min' => $t->min_days, 'max' => $t->max_days,
            'price' => $t->price, 'note' => $t->note,
        ])->values();

        $branchData = $branches->map(fn ($b) => [
            'id' => $b->id, 'slug' => $b->slug, 'name' => $b->name,
            'dist' => number_format((float) $b->distance_km, 1, ',', ' ').' км',
        ])->values();

        $extrasData = $product->extras->map(fn ($x) => [
            'id' => $x->id, 'name' => $x->name, 'sub' => $x->sub, 'price' => $x->price,
        ])->values();

        $busyData = $branches->mapWithKeys(fn ($b) => [
            $b->id => ($busy[$b->id] ?? collect())->all(),
        ]);
    @endphp

    <div class="container-bur"
         x-data="pdp({
            tiers: {{ Js::from($tiers) }},
            branches: {{ Js::from($branchData) }},
            extras: {{ Js::from($extrasData) }},
            busy: {{ Js::from($busyData) }},
            deposit: {{ $product->deposit }},
            today: '{{ now()->toDateString() }}',
            from: '{{ request('from', now()->toDateString()) }}',
         })">

        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            $product->category->parent?->name ?? 'Каталог' => $product->category->parent
                ? route('category', $product->category->parent)
                : route('catalog.index'),
            $product->category->name => route('category', $product->category),
            $product->name => null,
        ]" />

        {{-- Верхній блок: галерея + конверсійне ядро --}}
        <div class="grid gap-8 md:[grid-template-columns:1fr_420px]">

            {{-- ——— Галерея ——— --}}
            <div class="grid gap-3 [grid-template-columns:72px_1fr] max-xs:[grid-template-columns:64px_1fr] max-sm:[grid-template-columns:1fr]">
                <div class="flex flex-col gap-2 max-sm:order-2 max-sm:flex-row max-sm:overflow-x-auto">
                    @foreach (['загальний вигляд', 'у кейсі', 'що входить у комплект', 'у роботі'] as $shot)
                        <x-image-slot :label="$shot" ratio="1/1"
                                      class="w-18 shrink-0 rounded-[6px] border border-border-1 max-sm:w-16" />
                    @endforeach
                </div>

                {{-- LCP-елемент сторінки: статичне фото, не карусель і не відео --}}
                <x-image-slot :label="$product->brand->name.' '.$product->name"
                              class="rounded-[12px] border border-border-1 max-sm:order-1" />
            </div>

            {{-- ——— Конверсійне ядро ——— --}}
            <div class="md:sticky md:top-[88px] md:self-start">
                <a href="{{ route('brand', $product->brand) }}" class="text-[13px] text-text-3 hover:text-brand">
                    {{ $product->brand->name }}
                </a>

                <h1 class="t-h1 mt-1">{{ $product->name }}</h1>

                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-text-3">
                    <span>арт. {{ $product->sku }}</span>
                    <span class="text-star">★</span>
                    <span class="font-semibold text-text-1">{{ str_replace('.', ',', (string) $product->rating) }}</span>
                    <a href="#reviews" class="text-text-3 underline">· {{ $product->reviews_count }} відгуків</a>
                </div>

                {{-- ⭐ Тарифна сходинка --}}
                <div class="mt-5 grid grid-cols-3 gap-2" role="group" aria-label="Тарифна сходинка">
                    <template x-for="t in tiers" :key="t.min">
                        <button type="button" @click="setDays(t.min)"
                                :class="isActiveTier(t)
                                    ? 'border-2 border-brand bg-brand-tint p-[13px]'
                                    : 'border border-border-1 bg-surface-0 p-3.5'"
                                class="cursor-pointer rounded-[8px] text-left transition-all duration-150">
                            <div class="text-xs font-medium text-text-2" x-text="t.label"></div>
                            <div class="mt-1 font-mono text-[22px] font-bold" x-text="t.price + ' ₴'"></div>
                            <div class="text-[11px] text-text-3">за день</div>
                            <div class="mt-1 inline-block rounded-[2px] px-1.5 py-px text-[11px] font-semibold"
                                 :class="t.note.startsWith('−') ? 'bg-success-bg text-success-text' : 'text-text-3'"
                                 x-text="t.note"></div>
                            {{-- Стовпчик робить падіння ціни видимим, а не тільки читабельним --}}
                            <div class="mt-2 rounded-[3px] transition-colors duration-150"
                                 :class="isActiveTier(t) ? 'bg-brand' : 'bg-border-1'"
                                 :style="`height: ${barHeight(t)}px`"></div>
                        </button>
                    </template>
                </div>

                {{-- Повзунок днів --}}
                <div class="mt-5">
                    <div class="flex items-baseline justify-between">
                        <label for="days" class="text-sm font-medium">На скільки днів?</label>
                        <span class="font-mono text-sm font-semibold" x-text="daysLabel"></span>
                    </div>

                    <div class="mt-2 flex items-center gap-3">
                        <button type="button" @click="setDays(days - 1)" aria-label="Менше днів"
                                class="size-11 shrink-0 cursor-pointer rounded-[6px] border border-border-1 text-lg">−</button>

                        <input id="days" type="range" min="1" max="30" step="1"
                               class="days-range flex-1" :style="trackStyle"
                               :value="days" @input="setDays(+$event.target.value)">

                        <button type="button" @click="setDays(days + 1)" aria-label="Більше днів"
                                class="size-11 shrink-0 cursor-pointer rounded-[6px] border border-border-1 text-lg">+</button>
                    </div>

                    <div class="mt-1 flex justify-between font-mono text-[11px] text-text-3">
                        <span>1</span><span>7</span><span>14</span><span>30</span>
                    </div>
                </div>

                {{-- Календар доступності --}}
                <div class="mt-5 rounded-[12px] border border-border-1 bg-surface-0 p-4">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium">Дати оренди</span>
                        <span class="font-mono text-xs text-text-2" x-text="rangeLabel"></span>
                    </div>

                    <div class="mt-3 flex items-center justify-between">
                        <button type="button" @click="shiftMonth(-1)" class="cursor-pointer p-1.5" aria-label="Попередній місяць">
                            <x-icon name="chevron-left" class="size-4" />
                        </button>
                        <span class="text-sm font-semibold" x-text="monthTitle"></span>
                        <button type="button" @click="shiftMonth(1)" class="cursor-pointer p-1.5" aria-label="Наступний місяць">
                            <x-icon name="chevron-right" class="size-4" />
                        </button>
                    </div>

                    <div class="mt-2 grid grid-cols-7 text-center font-mono text-[11px] text-text-3">
                        <template x-for="w in weekdays" :key="w"><span x-text="w"></span></template>
                    </div>

                    <div class="mt-1 grid grid-cols-7 text-center font-mono text-[13px]">
                        <template x-for="(cell, i) in grid" :key="i">
                            <div>
                                <template x-if="cell.blank"><span class="block min-h-10"></span></template>
                                <template x-if="!cell.blank">
                                    <button type="button" @click="pickDate(cell)"
                                            :disabled="cell.busy || cell.past"
                                            :class="cellClass(cell)"
                                            class="block min-h-10 w-full leading-10"
                                            x-text="cell.day"></button>
                                </template>
                            </div>
                        </template>
                    </div>

                    {{-- Правда про наявність, а не «залиште заявку і ми уточнимо» --}}
                    <p x-show="conflict" x-cloak
                       class="mt-3 rounded-[6px] border border-warning-border bg-warning-bg px-3 py-2 text-[13px] text-warning-text">
                        Обрані дати частково зайняті.
                        <template x-if="freeFrom">
                            <span>Повністю вільний з <span class="font-mono font-semibold" x-text="freeFrom"></span></span>
                        </template>
                        <template x-if="altBranch">
                            <span> — або вільний на ці дати у філії <span class="font-semibold" x-text="altBranch.name"></span>.</span>
                        </template>
                    </p>
                </div>

                {{-- Вибір філії --}}
                <fieldset class="mt-5">
                    <legend class="text-sm font-medium">Звідки забрати</legend>

                    <div class="mt-2 space-y-2">
                        <template x-for="b in branches" :key="b.id">
                            <label :class="branchId === b.id
                                        ? 'border-2 border-brand bg-brand-tint p-[11px_13px]'
                                        : 'border border-border-1 bg-surface-0 p-3.5'"
                                   class="flex min-h-14 cursor-pointer items-center gap-2.5 rounded-[8px]">
                                <input type="radio" name="branch" class="sr-only" :value="b.id"
                                       :checked="branchId === b.id" @change="pickBranch(b)">
                                <span class="size-[18px] shrink-0 rounded-full bg-white"
                                      :class="branchId === b.id ? 'border-[5px] border-brand' : 'border-[1.5px] border-border-2'"></span>
                                <span class="text-sm font-medium" x-text="b.name"></span>
                                <span class="font-mono text-xs text-text-3" x-text="b.dist"></span>
                                <span class="ml-auto text-right text-xs font-semibold"
                                      :class="branchStatus(b).ok ? 'text-success-text' : 'text-danger-text'"
                                      x-text="branchStatus(b).text"></span>
                            </label>
                        </template>
                    </div>

                    <p class="mt-2 text-[13px] text-text-3">
                        або <a href="{{ route('delivery') }}">доставка {{ $city->name_locative }} — {{ $city->delivery_note }}</a>
                    </p>
                </fieldset>

                {{-- Витратники --}}
                @if ($product->extras->isNotEmpty())
                    <div class="mt-5">
                        <div class="text-sm font-medium">Потрібно докупити</div>
                        <div class="mt-2 space-y-2">
                            <template x-for="x in extras" :key="x.id">
                                <label :class="picked[x.id]
                                            ? 'border-2 border-brand bg-brand-tint p-[13px_15px]'
                                            : 'border border-border-1 bg-surface-0 p-4'"
                                       class="flex cursor-pointer items-center gap-3 rounded-[8px]">
                                    <input type="checkbox" class="sr-only" :checked="picked[x.id]" @change="toggleExtra(x.id)">
                                    <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-[4px]"
                                          :class="picked[x.id] ? 'bg-brand' : 'border-[1.5px] border-border-2 bg-white'">
                                        <svg x-show="picked[x.id]" class="size-3 text-white" viewBox="0 0 24 24" fill="none"
                                             stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="m5 13 4 4L19 7" />
                                        </svg>
                                    </span>
                                    <span>
                                        <span class="block text-sm font-medium" x-text="x.name"></span>
                                        <span class="block text-xs text-text-3" x-text="x.sub"></span>
                                    </span>
                                    <span class="ml-auto font-mono text-sm font-bold" x-text="x.price + ' ₴'"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                @endif

                {{-- Підсумок --}}
                <div class="mt-5 rounded-[12px] border border-border-1 bg-surface-0 p-4">
                    <div class="flex justify-between text-sm">
                        <span class="text-text-2">Оренда</span>
                        <span class="font-mono font-semibold" x-text="rentLine"></span>
                    </div>

                    <div class="mt-1.5 flex justify-between text-sm" x-show="extrasSum > 0" x-cloak>
                        <span class="text-text-2">Витратники <span class="text-text-3">(купівля)</span></span>
                        <span class="font-mono font-semibold" x-text="money(extrasSum)"></span>
                    </div>

                    <div class="mt-1.5 flex justify-between text-sm">
                        <span class="text-text-2">Самовивіз · <span x-text="branch.name"></span></span>
                        <span class="font-mono font-semibold">0 ₴</span>
                    </div>

                    {{-- Застава відділена бордером: це не витрата, вона повертається --}}
                    <div class="mt-3 flex justify-between border-t border-border-1 pt-3 text-sm">
                        <span class="text-text-2">Застава <span class="text-text-3">(повертається)</span></span>
                        <span class="font-mono font-semibold">{{ number_format($product->deposit, 0, ',', ' ') }} ₴</span>
                    </div>

                    <div class="mt-3 flex items-baseline justify-between border-t border-border-1 pt-3">
                        <span class="text-sm font-semibold">До сплати зараз</span>
                        <span class="font-mono text-[26px] font-bold" x-text="money(total)"></span>
                    </div>
                </div>

                <button type="button"
                        @click="book({{ Js::from([
                            'id' => $product->id, 'slug' => $product->slug,
                            'name' => $product->name, 'brand' => $product->brand->name,
                        ]) }})"
                        class="mt-4 h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                    Забронювати
                </button>

                <button type="button" @click="$dispatch('callback-open')"
                        class="mt-2 h-13 w-full cursor-pointer rounded-[6px] border-[1.5px] border-text-1 bg-surface-0 text-base font-semibold">
                    Передзвоніть мені
                </button>

                <div class="mt-2 flex justify-center gap-4 text-[13px]">
                    <a href="https://t.me/burrental">Telegram</a>
                    <a href="viber://chat?number=%2B380672458080">Viber</a>
                </div>

                <x-trust-lines class="mt-4" />
            </div>
        </div>

        {{-- ——— Нижче першого екрана ——— --}}

        {{-- Таби на desktop, акордеони на mobile --}}
        <x-section id="details">
            <div x-data="{ tab: 'specs' }" class="overflow-hidden rounded-[12px] border border-border-1 bg-surface-0">
                <div class="flex overflow-x-auto border-b border-border-1">
                    @foreach (['specs' => 'Характеристики', 'desc' => 'Опис', 'kit' => 'Комплектація', 'manual' => 'Інструкція'] as $key => $label)
                        <button type="button" @click="tab = '{{ $key }}'"
                                :class="tab === '{{ $key }}'
                                    ? 'border-brand font-semibold text-text-1'
                                    : 'border-transparent text-text-2'"
                                class="cursor-pointer whitespace-nowrap border-b-[3px] px-5 py-3.5 text-[15px]">
                            {{ $label }}
                        </button>
                    @endforeach
                </div>

                <div class="p-5">
                    <div x-show="tab === 'specs'">
                        <table class="w-full text-sm">
                            <tbody>
                                @foreach ($product->specs ?? [] as $name => $value)
                                    <tr class="border-b border-surface-2 last:border-0 {{ $loop->index < 5 ? 'bg-surface-1' : '' }}">
                                        <th scope="row" class="py-2.5 pr-4 text-left font-normal text-text-2">{{ $name }}</th>
                                        <td class="py-2.5 text-right font-mono font-semibold">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div x-show="tab === 'desc'" x-cloak class="max-w-[760px] space-y-3 text-[15px] leading-[26px] text-text-2">
                        <p>{{ $product->lead }}</p>
                        @if ($product->description)
                            <p>{{ $product->description }}</p>
                        @endif
                    </div>

                    <div x-show="tab === 'kit'" x-cloak>
                        <ul class="space-y-2 text-sm">
                            @foreach ($product->kit ?? [] as $line)
                                <li class="flex items-start gap-2">
                                    <x-icon name="check" class="mt-0.5 size-4 shrink-0 text-success" />{{ $line }}
                                </li>
                            @endforeach
                        </ul>
                        @if ($product->not_included)
                            <p class="mt-3 rounded-[6px] border border-warning-border bg-warning-bg px-3 py-2 text-[13px] text-warning-text">
                                {{ implode('. ', $product->not_included) }} — <a href="#extras" class="underline">докупіть нижче</a>.
                            </p>
                        @endif
                    </div>

                    <div x-show="tab === 'manual'" x-cloak class="space-y-2 text-sm">
                        <a href="{{ $product->manual_url }}" class="flex items-center gap-2">
                            <x-icon name="file" class="size-4" /> Інструкція PDF · 2,4 МБ
                        </a>
                        @if ($product->video_url)
                            <a href="{{ $product->video_url }}" class="flex items-center gap-2">
                                <x-icon name="play" class="size-4" /> Відео: перші 5 хвилин роботи
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </x-section>

        @if ($product->related->isNotEmpty())
            <x-section title="З цим орендують"
                       lead="Позиції, які беруть у пару до цієї моделі.">
                <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
                    @foreach ($product->related as $item)
                        <x-product-card :product="$item" :branches="$branches" />
                    @endforeach
                </div>
            </x-section>
        @endif

        @if ($product->similar->isNotEmpty())
            <x-section title="Схожі моделі — що обрати"
                       lead="Різниця по трьох параметрах, які реально впливають на роботу.">
                <div class="overflow-x-auto rounded-[12px] border border-border-1 bg-surface-0">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead>
                            <tr class="border-b border-border-1">
                                <th class="p-4 text-left font-normal text-text-3">Параметр</th>
                                <th class="p-4 text-left">
                                    {{ $product->name }}
                                    <span class="block text-[11px] font-normal text-text-3">ця сторінка</span>
                                </th>
                                @foreach ($product->similar as $other)
                                    <th class="p-4 text-left">
                                        <a href="{{ route('product', $other) }}">{{ $other->name }}</a>
                                        <span class="block text-[11px] font-normal text-text-3">
                                            {{ $other->weight_kg > $product->weight_kg ? 'потужніший' : 'легший' }}
                                        </span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (array_slice(array_keys($product->specs ?? []), 0, 3) as $spec)
                                <tr class="border-b border-surface-2">
                                    <th scope="row" class="p-4 text-left font-normal text-text-2">{{ $spec }}</th>
                                    <td class="p-4 font-mono font-semibold">{{ $product->specs[$spec] ?? '—' }}</td>
                                    @foreach ($product->similar as $other)
                                        <td class="p-4 font-mono">{{ $other->specs[$spec] ?? '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                            <tr>
                                <th scope="row" class="p-4 text-left font-normal text-text-2">Ціна від</th>
                                <td class="p-4 font-mono font-bold">{{ $product->min_price }} ₴/день</td>
                                @foreach ($product->similar as $other)
                                    <td class="p-4 font-mono">{{ $other->min_price }} ₴/день</td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
            </x-section>
        @endif

        <x-rent-vs-buy :rent-week="$product->min_price * 7" :own-year="$product->retail_price ?? 8800" />

        <x-faq-list :faqs="$product->faqs" :title="'Питання про оренду: '.$product->category->name" />

        <x-reviews :reviews="$product->reviews" title="Відгуки"
                   :rating="$product->rating" :count="$product->reviews_count" />

        <x-section title="Де забрати {{ $city->name_locative }}">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($branches as $branch)
                    <x-branch-card :branch="$branch" :city="$city" />
                @endforeach
            </div>
        </x-section>

        <x-seo-text :title="'Оренда '.$product->brand->name.' '.$city->name_locative" :body="$product->seo_text" />

        <x-district-links :city="$city" :title="'Оренда '.($product->category->name_genitive ?? 'інструменту').' у районах '.$city->name" />

        {{-- Мобільна sticky-панель: підсумок + бронь завжди під рукою --}}
        <div class="fixed inset-x-0 bottom-[76px] z-90 border-t border-border-1 bg-surface-0 px-4 py-3 shadow-[var(--shadow-float)] nav:hidden">
            <div class="flex items-center gap-3">
                <div class="flex-1">
                    <div class="font-mono text-lg font-bold" x-text="money(total)"></div>
                    <div class="text-[11px] text-text-3">
                        <span x-text="daysLabel"></span> · з них застава {{ number_format($product->deposit, 0, ',', ' ') }} ₴
                    </div>
                </div>
                <button type="button"
                        @click="book({{ Js::from(['id' => $product->id, 'slug' => $product->slug, 'name' => $product->name, 'brand' => $product->brand->name]) }})"
                        class="h-11 cursor-pointer rounded-[6px] bg-brand px-5 text-sm font-semibold text-white">
                    Забронювати
                </button>
            </div>
        </div>
    </div>
@endsection

@push('head')
    @php
        $productSchema = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->brand->name.' '.$product->name,
            'sku' => $product->sku,
            'brand' => ['@type' => 'Brand', 'name' => $product->brand->name],
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'ratingValue' => $product->rating,
                'reviewCount' => $product->reviews_count,
            ],
            'offers' => [
                '@type' => 'Offer',
                'price' => $product->min_price,
                'priceCurrency' => 'UAH',
                'availability' => 'https://schema.org/InStock',
                'url' => route('product', $product),
            ],
        ];
    @endphp

    <script type="application/ld+json">{!! json_encode($productSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
