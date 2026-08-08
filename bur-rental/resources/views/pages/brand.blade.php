@extends('layouts.app')

@section('title', 'Оренда інструменту '.$brand->name.' '.$city->name_locative.' — БУР')
@section('description', Str::limit($brand->about, 155))

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            'Бренди' => route('catalog.index'),
            $brand->name => null,
        ]" />

        <div class="grid gap-6 md:[grid-template-columns:1fr_320px]">
            <div>
                <h1 class="t-h1">Оренда {{ $brand->name }} {{ $city->name_locative }}</h1>
                <p class="mt-3 max-w-[720px] text-[15px] leading-[26px] text-text-2">{{ $brand->about }}</p>

                @if ($brand->why)
                    <div class="mt-4 rounded-[12px] border border-brand bg-brand-tint p-5">
                        <h2 class="t-h3">Чому тримаємо {{ $brand->name }} у парку</h2>
                        <p class="mt-1 text-sm leading-[22px] text-text-2">{{ $brand->why }}</p>
                    </div>
                @endif
            </div>

            <aside class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-text-2">Країна</dt>
                        <dd class="font-semibold">{{ $brand->country }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-2">Позицій у прокаті</dt>
                        <dd class="font-mono font-semibold">{{ $products->total() }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-text-2">Категорій</dt>
                        <dd class="font-mono font-semibold">{{ $categories->count() }}</dd>
                    </div>
                </dl>
            </aside>
        </div>

        <x-section title="Уся техніка {{ $brand->name }}">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(280px,1fr))]">
                @foreach ($products as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
            <div class="mt-6">{{ $products->links() }}</div>
        </x-section>

        <x-section title="Категорії {{ $brand->name }}">
            <div class="flex flex-wrap gap-2">
                @foreach ($categories as $category)
                    <a href="{{ route('category', $category) }}"
                       class="inline-flex min-h-11 items-center rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                        {{ $category->name }}
                    </a>
                @endforeach
            </div>
        </x-section>
    </div>
@endsection
