@extends('layouts.app')

@section('title', 'Обране — БУР')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Обране' => null]" />

        <h1 class="t-h1">Обране</h1>

        @if ($client)
            <p class="mt-2 text-sm text-text-2">Список прив'язаний до вашого номера — він той самий на всіх пристроях.</p>

            @if ($products->isEmpty())
                <x-section>
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-8 text-center">
                        <p class="text-sm text-text-2">Поки нічого не збережено. Серце є на кожній картці товару.</p>
                        <a href="{{ route('catalog.index') }}"
                           class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                            До каталогу
                        </a>
                    </div>
                </x-section>
            @else
                <x-section>
                    <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                        @foreach ($products as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>
                </x-section>
            @endif
        @else
            {{--
                Гість: список лежить у localStorage, тому картки добираються
                окремим запитом за id. Так сторінка не тягне весь каталог
                заради трьох позицій.
            --}}
            <p class="mt-2 text-sm text-text-2">
                Список зберігається у цьому браузері.
                <a href="{{ route('cabinet.login') }}">Увійдіть</a> — і він буде з вами на всіх пристроях.
            </p>

            <x-section>
                <div x-data="favouritesPage" x-init="load()">
                    <div x-show="loading" class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                        @for ($i = 0; $i < 3; $i++)
                            <x-product-card-skeleton />
                        @endfor
                    </div>

                    <div x-show="!loading" x-cloak x-html="html"></div>

                    <div x-show="!loading && empty" x-cloak
                         class="rounded-[12px] border border-border-1 bg-surface-0 p-8 text-center">
                        <p class="text-sm text-text-2">Поки нічого не збережено. Серце є на кожній картці товару.</p>
                        <a href="{{ route('catalog.index') }}"
                           class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                            До каталогу
                        </a>
                    </div>
                </div>
            </x-section>
        @endif
    </div>
@endsection
