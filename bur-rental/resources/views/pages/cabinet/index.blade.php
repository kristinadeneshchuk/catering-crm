@extends('layouts.app')

@section('title', 'Кабінет — БУР')

@section('content')
    <div class="container-bur max-w-[860px]">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Кабінет' => null]" />

        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h1 class="t-h1">{{ $client->name ? $client->name : 'Кабінет' }}</h1>
                <p class="mt-1 font-mono text-sm text-text-2">{{ $client->display_phone }}</p>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('cabinet.profile') }}" class="text-sm font-semibold text-brand">Мої дані</a>
                <form method="post" action="{{ route('cabinet.logout') }}">
                    @csrf
                    <button type="submit" class="cursor-pointer text-sm text-text-3 hover:text-text-1">Вийти</button>
                </form>
            </div>
        </div>

        <x-section title="Активні оренди">
            @if ($active->isEmpty())
                <div class="rounded-[12px] border border-border-1 bg-surface-0 p-8 text-center">
                    <p class="text-sm text-text-2">Зараз на руках нічого немає.</p>
                    <a href="{{ route('catalog.index') }}"
                       class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                        До каталогу
                    </a>
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($active as $booking)
                        <x-booking-row :booking="$booking" />
                    @endforeach
                </div>
            @endif
        </x-section>

        @if ($favourites->isNotEmpty())
            <x-section title="Обране" action="Усе обране" :actionUrl="route('favourites')">
                <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                    @foreach ($favourites as $product)
                        <x-product-card :product="$product" />
                    @endforeach
                </div>
            </x-section>
        @endif

        @if ($past->isNotEmpty())
            <x-section title="Історія">
                <div class="space-y-3">
                    @foreach ($past as $booking)
                        <x-booking-row :booking="$booking" />
                    @endforeach
                </div>
            </x-section>
        @endif

        <p class="mt-10 text-[13px] text-text-3">
            Питання по замовленню — {{ $city->phone }}, щодня з 8:00 до 20:00.
        </p>
    </div>
@endsection
