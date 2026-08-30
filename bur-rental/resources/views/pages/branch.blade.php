@extends('layouts.app')

@section('title', 'Прокат інструменту — філія «'.$branch->name.'», '.$city->name)
@section('description', 'Оренда інструменту на філії «'.$branch->name.'»: '.$branch->address.', '.$branch->hours.'. Живий залишок по датах.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            $city->name => route('city', $city),
            'Філії' => route('city', $city),
            $branch->name => null,
        ]" />

        <h1 class="t-h1">Філія «{{ $branch->name }}»</h1>

        <div class="mt-5 grid gap-6 md:[grid-template-columns:1fr_360px]">
            <div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach (['вхід і вивіска', 'зал видачі', 'склад важкої техніки', 'парковка'] as $shot)
                        <x-image-slot :label="$shot" class="rounded-[8px] border border-border-1" />
                    @endforeach
                </div>

                <x-section title="Як доїхати">
                    <p class="max-w-[720px] text-[15px] leading-[26px] text-text-2">{{ $branch->directions }}</p>
                    <div class="mt-4 rounded-[12px] border border-border-1 bg-surface-2"
                         style="aspect-ratio: 16/7"
                         role="img" aria-label="Карта: {{ $branch->address }}">
                        {{-- Карта вантажиться ліниво з зарезервованою висотою: LCP не має падати на неї --}}
                        <div class="flex h-full items-center justify-center text-sm text-text-3">
                            Карта · {{ $branch->address }}
                        </div>
                    </div>
                </x-section>

                <x-section title="Живий залишок на цій філії"
                           lead="Те, що бачите вільним, реально стоїть на полиці саме тут.">
                    <div class="overflow-x-auto rounded-[12px] border border-border-1 bg-surface-0">
                        <table class="w-full min-w-[560px] text-sm">
                            <thead>
                                <tr class="border-b border-border-1 text-left text-text-3">
                                    <th class="p-4 font-normal">Модель</th>
                                    <th class="p-4 font-normal">Наявність</th>
                                    <th class="p-4 font-normal">Ціна від</th>
                                    <th class="p-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stock as $product)
                                    <tr class="border-b border-surface-2 last:border-0">
                                        <th scope="row" class="p-4 text-left font-medium">
                                            <span class="block text-[11px] font-normal text-text-3">{{ $product->brand->name }}</span>
                                            {{ $product->name }}
                                        </th>
                                        <td class="p-4">
                                            <x-availability-badge :product="$product" :branches="collect([$branch])" />
                                        </td>
                                        <td class="p-4 font-mono font-bold">{{ $product->min_price }} ₴</td>
                                        <td class="p-4 text-right">
                                            <a href="{{ route('product', $product) }}?branch={{ $branch->slug }}"
                                               class="text-sm font-semibold text-brand">Забронювати →</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-section>

                <x-section title="Тільки на цій філії">
                    <ul class="grid gap-2 sm:grid-cols-2">
                        @foreach ([
                            'Власний сервіс — дрібний ремонт вашого інструменту за 1 день',
                            'Заточка свердел, ланцюгів і дисків',
                            'Важка техніка з гідробортом — віброплити від 160 кг, компресори',
                            'Продаж витратників — бури, диски, круги, мішки',
                            'Безготівка для юросіб на місці',
                        ] as $service)
                            <li class="flex items-start gap-2 rounded-[8px] border border-border-1 bg-surface-0 p-3.5 text-sm">
                                <x-ui-icon name="check" class="mt-0.5 size-4 shrink-0 text-success" />{{ $service }}
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            </div>

            <aside class="md:sticky md:top-[88px] md:self-start">
                <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                    <div class="rounded-[2px] border border-success-border bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success-text inline-block">
                        Зараз відчинено
                    </div>
                    <address class="mt-3 not-italic">
                        <div class="text-[15px] font-semibold">{{ $branch->address }}</div>
                        <div class="mt-1 text-sm text-text-2">{{ $branch->hours }}</div>
                        <div class="text-sm text-text-2">повернення техніки {{ $branch->last_intake }}</div>
                        <div class="mt-3 font-mono text-lg font-semibold">{{ $branch->phone }}</div>
                        <div class="text-sm text-text-3">{{ $branch->manager }}, менеджер філії</div>
                    </address>

                    <a href="https://www.google.com/maps/search/{{ urlencode($branch->address) }}"
                       class="mt-4 flex h-11 items-center justify-center rounded-[6px] border-[1.5px] border-text-1 text-sm font-semibold text-text-1 no-underline hover:no-underline">
                        Прокласти маршрут
                    </a>
                    <button type="button" @click="$dispatch('callback-open')"
                            class="mt-2 h-11 w-full cursor-pointer rounded-[6px] bg-brand text-sm font-semibold text-white hover:bg-brand-hover">
                        Передзвоніть мені
                    </button>
                </div>

                @if ($others->isNotEmpty())
                    <div class="mt-4 rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <h2 class="t-h3">Інші філії {{ $city->name_locative }}</h2>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach ($others as $other)
                                <li>
                                    <a href="{{ route('branch', [$city, $other]) }}">{{ $other->name }}</a>
                                    <span class="block text-xs text-text-3">{{ $other->address }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </aside>
        </div>

        <x-reviews :reviews="$branch->reviews" :title="'Відгуки про філію «'.$branch->name.'»'"
                   :rating="$branch->rating" :count="$branch->reviews_count"
                   google-url="https://www.google.com/maps" />

        <x-district-links :city="$city" title="Райони, які обслуговує ця філія" />
    </div>
@endsection

@push('head')
    {{--
        Філія — це фізична точка, куди приїжджають. Саме ця розмітка зшиває
        сторінку з карткою в Google Картах, а локальна видача для оренди
        інструменту дає більше, ніж загальна.
    --}}
    @php
        $branchSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => 'БУР — '.$branch->name,
            'url' => route('branch', [$city, $branch]),
            'telephone' => $city->phone,
            'openingHours' => $branch->hours,
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $branch->address,
                'addressLocality' => $city->name,
                'addressCountry' => 'UA',
            ],
            'geo' => $branch->lat && $branch->lng ? [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $branch->lat,
                'longitude' => (float) $branch->lng,
            ] : null,
            'parentOrganization' => ['@id' => url('/').'#organization'],
        ]);
    @endphp

    <script type="application/ld+json">{!! json_encode($branchSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
