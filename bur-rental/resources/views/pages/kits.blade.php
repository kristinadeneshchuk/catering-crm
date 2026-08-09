@extends('layouts.app')

@section('title', 'Комплекти інструменту під задачу — БУР')
@section('description', 'Готові набори інструменту під конкретну роботу: плитка, стяжка, демонтаж, бруківка, електрика.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Комплекти під задачу' => null]" />

        <h1 class="t-h1">Комплекти під задачу</h1>
        <p class="mt-2 max-w-[720px] text-[15px] leading-[26px] text-text-2">
            Кажете, що робите — отримуєте набір, яким це роблять. Комплектом дешевше, ніж брати
            ті самі позиції окремо.
        </p>

        <div class="mt-6 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(300px,1fr))]">
            @foreach ($kits as $kit)
                <a href="{{ route('kit', $kit) }}"
                   class="flex flex-col rounded-[12px] border border-border-1 bg-surface-0 p-5 no-underline hover:border-brand hover:no-underline">
                    <h2 class="t-h3 text-text-1">{{ $kit->name }}</h2>
                    <p class="mt-1 text-[13px] text-text-3">{{ $kit->task }}</p>

                    <ul class="mt-3 space-y-1 text-[13px] text-text-2">
                        @foreach ($kit->items->take(3) as $item)
                            <li class="flex items-start gap-2">
                                <x-ui-icon name="check" class="mt-0.5 size-3.5 shrink-0 text-success" />
                                {{ $item->product->name }}
                            </li>
                        @endforeach
                    </ul>

                    <div class="mt-auto pt-4">
                        <span class="font-mono text-lg font-bold text-brand">
                            від {{ number_format($kit->priceFor(7), 0, ',', ' ') }} ₴
                        </span>
                        <span class="text-xs text-text-3">/день при оренді від 7 днів</span>
                        <div class="mt-1 inline-block rounded-[2px] bg-success-bg px-1.5 py-px text-[11px] font-semibold text-success-text">
                            −{{ $kit->discount_percent }}% проти окремих позицій
                        </div>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
@endsection
