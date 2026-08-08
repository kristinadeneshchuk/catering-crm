@extends('layouts.app')

@section('title', 'Сторінку не знайдено — БУР')

@section('content')
    {{-- 404 не порожня: пошук + популярні категорії, щоб людина не пішла --}}
    <div class="container-bur max-w-[720px] py-12 text-center">
        <div class="font-mono text-[64px] font-bold text-brand">404</div>
        <h1 class="t-h1 mt-2">Такої сторінки немає</h1>
        <p class="mt-2 text-[15px] text-text-2">
            Можливо, модель зняли з прокату або посилання застаріло. Спробуйте пошук.
        </p>

        <form action="{{ route('search') }}" method="get"
              class="mx-auto mt-6 flex h-13 max-w-[480px] items-center gap-2 rounded-[8px] border-2 border-brand bg-surface-0 pl-4 pr-1">
            <input type="search" name="q" placeholder="Модель, категорія або задача…"
                   class="h-full flex-1 bg-transparent text-[15px] outline-none placeholder:text-text-3">
            <button type="submit" class="h-11 cursor-pointer rounded-[6px] bg-brand px-4 text-sm font-semibold text-white">Знайти</button>
        </form>

        <div class="mt-8 flex flex-wrap justify-center gap-2">
            @foreach (\App\Models\Category::roots()->take(6)->get() as $category)
                <a href="{{ route('category', $category) }}"
                   class="inline-flex min-h-11 items-center rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                    {{ $category->name }}
                </a>
            @endforeach
        </div>
    </div>
@endsection
