@extends('layouts.app')

@section('title', 'Оренда інструменту на '.$district->name.' — БУР '.$city->name)
@section('description', 'Прокат будівельного інструменту в районі '.$district->name.': найближча філія, доставка, популярні позиції.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            $city->name => route('city', $city),
            $district->name => null,
        ]" />

        <h1 class="t-h1">Оренда інструменту — {{ $district->name }}</h1>
        @if ($district->intro)
            <p class="mt-3 max-w-[760px] text-[15px] leading-[26px] text-text-2">{{ $district->intro }}</p>
        @endif

        @if ($nearest)
            <x-section title="Найближча філія">
                <div class="grid gap-4 md:grid-cols-2">
                    <x-branch-card :branch="$nearest" :city="$city" />
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <h3 class="t-h3">Доставка в район</h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            @foreach ($zones->take(2) as $zone)
                                <li class="flex justify-between gap-4">
                                    <span class="text-text-2">{{ $zone->name }}</span>
                                    <span class="font-mono font-semibold">{{ $zone->price }} ₴</span>
                                </li>
                            @endforeach
                        </ul>
                        <p class="mt-3 text-[13px] text-text-3">
                            Замовлення до 16:00 — привеземо того ж дня.
                            <a href="{{ route('delivery') }}">Умови доставки →</a>
                        </p>
                    </div>
                </div>
            </x-section>
        @endif

        <x-section title="Популярне в районі">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                @foreach ($popular as $product)
                    <x-product-card :product="$product" :branches="$city->branches" />
                @endforeach
            </div>
        </x-section>

        <x-district-links :city="$city" title="Сусідні райони" />
        <x-rent-vs-buy />
    </div>
@endsection
