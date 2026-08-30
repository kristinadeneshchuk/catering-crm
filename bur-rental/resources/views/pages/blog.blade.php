@extends('layouts.app')

@section('title', 'Як зробити роботу — статті БУР')
@section('description', 'Практичні розбори: що потрібно для стяжки, чим штробити під проводку, як ущільнити основу під бруківку. З розрахунками, строками й готовими комплектами інструменту.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Статті' => null]" />

        <h1 class="t-h1">Як зробити роботу</h1>
        <p class="mt-2 max-w-[640px] text-sm text-text-2">
            Розбори з цифрами: скільки матеріалу, чим ущільнювати, скільки сохне і що станеться,
            якщо поспішити. Наприкінці кожної — готовий комплект інструменту під цю задачу.
        </p>

        <x-section>
            <div class="grid gap-4 md:grid-cols-2">
                @foreach ($articles as $article)
                    <article class="flex flex-col rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <h2 class="text-[17px] font-semibold leading-snug">
                            <a href="{{ route('article', $article) }}" class="text-text-1 no-underline hover:text-brand hover:no-underline">
                                {{ $article->title }}
                            </a>
                        </h2>

                        <p class="mt-2 flex-1 text-sm text-text-2">{{ $article->excerpt }}</p>

                        <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 font-mono text-xs text-text-3">
                            <span>{{ $article->published_at?->format('d.m.Y') }}</span>
                            <span>· {{ $article->reading_minutes }} хв читання</span>
                            @if ($article->kit)
                                <span class="rounded-[2px] bg-brand-tint px-2 py-0.5 font-sans text-[11px] font-semibold text-brand-hover">
                                    комплект: {{ $article->kit->name }}
                                </span>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </x-section>

        <x-section title="Готові комплекти під задачу" action="Усі комплекти" :actionUrl="route('kits.index')">
            <p class="text-sm text-text-2">
                Якщо читати ніколи — візьміть готовий набір: у ньому вже зібрано все, що знадобиться,
                разом із витратниками.
            </p>
        </x-section>
    </div>
@endsection
