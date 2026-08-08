@extends('layouts.app')

@section('title', 'Каталог інструменту в оренду — БУР')
@section('description', 'Усі категорії прокату: перфоратори, віброплити, генератори, садова техніка, вимірювальні прилади.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Каталог' => null]" />

        <h1 class="t-h1">Каталог інструменту</h1>
        <p class="mt-2 max-w-[720px] text-[15px] leading-[26px] text-text-2">
            {{ $categories->sum('products_count') }}+ позицій у прокаті. Наявність по датах і філіях видно
            всередині кожної категорії.
        </p>

        <div class="mt-6 grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(260px,1fr))]">
            @foreach ($categories as $category)
                <a href="{{ route('category', $category) }}"
                   class="overflow-hidden rounded-[12px] border border-border-1 bg-surface-0 no-underline hover:border-brand hover:no-underline">
                    <x-image-slot :label="$category->name" ratio="16/10" />
                    <div class="p-4">
                        <div class="text-[15px] font-semibold text-text-1">{{ $category->name }}</div>
                        <p class="mt-1 line-clamp-2 text-[13px] text-text-2">{{ $category->lead }}</p>
                        <div class="mt-2 font-mono text-xs text-text-3">{{ $category->products_count }} позицій</div>
                    </div>
                </a>
            @endforeach
        </div>

        <x-section title="Комплекти під задачу" action="Усі комплекти" :action-url="route('kits.index')">
            <p class="text-sm text-text-2">Не знаєте назви інструменту — почніть із задачі.</p>
        </x-section>
    </div>
@endsection
