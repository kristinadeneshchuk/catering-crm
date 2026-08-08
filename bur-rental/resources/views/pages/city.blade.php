@extends('layouts.app')

@section('title', 'Прокат будівельного інструменту '.$city->name_locative.' — БУР')
@section('description', Str::limit($city->intro, 155))

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Міста' => route('contacts'), $city->name => null]" />

        <h1 class="t-h1">Прокат будівельного інструменту {{ $city->name_locative }}</h1>
        <p class="mt-3 max-w-[760px] text-[18px] leading-[28px] text-text-2">{{ $city->intro }}</p>

        <x-section title="Філії {{ $city->name_locative }}">
            <div class="grid gap-4 md:grid-cols-3">
                @foreach ($city->branches as $branch)
                    <x-branch-card :branch="$branch" :city="$city" />
                @endforeach
            </div>
        </x-section>

        <x-section title="Доставка {{ $city->name_locative }} та області">
            <div class="overflow-x-auto rounded-[12px] border border-border-1 bg-surface-0">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="border-b border-border-1 text-left text-text-3">
                            <th class="p-4 font-normal">Зона</th>
                            <th class="p-4 font-normal">Вартість</th>
                            <th class="p-4 font-normal">Коли привеземо</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($city->deliveryZones as $zone)
                            <tr class="border-b border-surface-2 last:border-0">
                                <th scope="row" class="p-4 text-left font-medium">
                                    {{ $zone->name }}
                                    @if ($zone->note)
                                        <span class="block text-[13px] font-normal text-text-3">{{ $zone->note }}</span>
                                    @endif
                                </th>
                                <td class="p-4 font-mono font-bold">{{ $zone->price ? number_format($zone->price, 0, ',', ' ').' ₴' : '0 ₴' }}</td>
                                <td class="p-4 text-text-2">{{ $zone->eta }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-[13px] text-text-3">
                Повні умови й доплати за важку техніку — на сторінці <a href="{{ route('delivery') }}">доставки й оплати</a>.
            </p>
        </x-section>

        <x-section title="Популярне {{ $city->name_locative }}" action="Увесь каталог" :action-url="route('catalog.index')">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                @foreach ($popular as $product)
                    <x-product-card :product="$product" :branches="$city->branches" />
                @endforeach
            </div>
        </x-section>

        <x-section title="Категорії">
            <div class="flex flex-wrap gap-2">
                @foreach ($categories as $category)
                    <a href="{{ route('category', $category) }}"
                       class="inline-flex min-h-11 items-center gap-2 rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                        {{ $category->name }}
                        <span class="font-mono text-xs text-text-3">{{ $category->products_count }}</span>
                    </a>
                @endforeach
            </div>
        </x-section>

        <x-district-links :city="$city" />
        <x-rent-vs-buy />
        <x-reviews :reviews="$city->reviews" :title="'Відгуки — '.$city->name" :rating="4.8" />
        <x-faq-list :faqs="$faqs" :title="'Питання про оренду '.$city->name_locative" />
    </div>
@endsection
