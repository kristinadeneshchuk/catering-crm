@extends('layouts.app')

@section('title', $term ? 'Пошук: '.$term.' — БУР' : 'Пошук — БУР')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Пошук' => null]" />

        <h1 class="t-h1">{{ $term ? 'Результати: «'.$term.'»' : 'Пошук' }}</h1>

        <form action="{{ route('search') }}" method="get" class="mt-4 flex h-13 max-w-[560px] items-center gap-2 rounded-[8px] border-2 border-brand bg-surface-0 pl-4 pr-1">
            <input type="search" name="q" value="{{ $term }}" placeholder="Модель, категорія або задача…"
                   class="h-full flex-1 bg-transparent text-[15px] outline-none placeholder:text-text-3" autofocus>
            <button type="submit" class="h-11 cursor-pointer rounded-[6px] bg-brand px-4 text-sm font-semibold text-white">Знайти</button>
        </form>

        @if ($term && $products->isEmpty() && $categories->isEmpty() && $kits->isEmpty())
            {{-- Порожній стан пропонує дію, а не просто повідомляє --}}
            <div class="mt-8 rounded-[12px] border border-border-1 bg-surface-0 p-8 text-center">
                <h2 class="t-h3">Нічого не знайшли за «{{ $term }}»</h2>
                <p class="mx-auto mt-2 max-w-[440px] text-sm text-text-2">
                    Скажіть, що робите — підберемо модель і витратники за 5 хвилин. Безкоштовно.
                </p>
                <button type="button" @click="$dispatch('callback-open')"
                        class="mt-4 h-11 cursor-pointer rounded-[6px] bg-brand px-5 text-sm font-semibold text-white">
                    Передзвоніть мені
                </button>
            </div>
        @endif

        @if ($categories->isNotEmpty())
            <x-section title="Категорії">
                <div class="flex flex-wrap gap-2">
                    @foreach ($categories as $category)
                        <a href="{{ route('category', $category) }}"
                           class="inline-flex min-h-11 items-center rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                            {{ $category->name }}
                        </a>
                    @endforeach
                </div>
            </x-section>
        @endif

        @if ($kits->isNotEmpty())
            <x-section title="Комплекти під задачу">
                <div class="flex flex-wrap gap-2">
                    @foreach ($kits as $kit)
                        <a href="{{ route('kit', $kit) }}"
                           class="inline-flex min-h-11 items-center rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                            {{ $kit->name }}
                        </a>
                    @endforeach
                </div>
            </x-section>
        @endif

        <x-section :title="$products->isNotEmpty() ? 'Товари' : ($term ? '' : 'Популярне')">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
                @foreach (($products->isNotEmpty() ? $products : ($term ? collect() : $popular)) as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        </x-section>
    </div>
@endsection
