@extends('layouts.app')

@section('title', $article->title.' — БУР')
@section('description', $article->excerpt)

@section('content')
    <div class="container-bur max-w-[760px]">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Статті' => route('blog.index'), $article->title => null]" />

        <h1 class="t-h1">{{ $article->title }}</h1>

        <div class="mt-2 flex flex-wrap items-center gap-x-3 font-mono text-xs text-text-3">
            <span>{{ $article->published_at?->format('d.m.Y') }}</span>
            <span>· {{ $article->reading_minutes }} хв читання</span>
        </div>

        {{--
            Типографіка статті задається тут, а не в markdown: автор пише текст,
            вигляд — справа дизайн-системи. Ширина рядка тримається біля 65
            символів, інакше око губить початок наступного.
        --}}
        <div class="prose-bur mt-6">
            {!! $article->html !!}
        </div>

        @if ($article->kit)
            <div class="mt-10 rounded-[12px] border border-brand bg-brand-tint p-6">
                <p class="text-[13px] font-semibold uppercase tracking-wide text-brand-hover">Готовий комплект</p>
                <h2 class="t-h3 mt-1">{{ $article->kit->name }}</h2>
                <p class="mt-1 text-sm text-text-2">{{ $article->kit->task }}</p>

                <ul class="mt-3 space-y-1 text-sm text-text-2">
                    @foreach ($article->kit->items as $item)
                        <li>· {{ $item->product->name }}</li>
                    @endforeach
                </ul>

                <a href="{{ route('kit', $article->kit) }}"
                   class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                    Подивитися комплект
                </a>
            </div>
        @elseif ($article->category)
            <div class="mt-10 rounded-[12px] border border-border-1 bg-surface-0 p-6">
                <h2 class="t-h3">{{ $article->category->name }} в оренду</h2>
                <p class="mt-1 text-sm text-text-2">Наявність по датах і філіях видно одразу, без дзвінків.</p>
                <a href="{{ route('category', $article->category) }}"
                   class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-5 text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                    До каталогу
                </a>
            </div>
        @endif

        @if ($others->isNotEmpty())
            <x-section title="Читайте також">
                <ul class="space-y-3">
                    @foreach ($others as $other)
                        <li>
                            <a href="{{ route('article', $other) }}" class="font-semibold">{{ $other->title }}</a>
                            <span class="block text-sm text-text-2">{{ $other->excerpt }}</span>
                        </li>
                    @endforeach
                </ul>
            </x-section>
        @endif
    </div>
@endsection

@push('head')
    @php
        $articleSchema = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Article',
            'headline' => $article->title,
            'description' => $article->excerpt,
            'datePublished' => $article->published_at?->toDateString(),
            'dateModified' => $article->updated_at->toDateString(),
            'inLanguage' => 'uk-UA',
            'mainEntityOfPage' => route('article', $article),
            'publisher' => ['@id' => url('/').'#organization'],
        ]);
    @endphp

    <script type="application/ld+json">{!! json_encode($articleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
