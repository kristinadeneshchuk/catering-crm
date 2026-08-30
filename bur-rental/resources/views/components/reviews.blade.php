@props(['reviews', 'title' => 'Відгуки', 'googleUrl' => null])

@php
    /*
     | Рейтинг рахується з тих самих відгуків, які видно на сторінці, і не
     | приймається ззовні. Раніше сюди передавали константу «4.8» — цифру,
     | за якою не стояло нічого. Так робити не можна навіть у верстці: це
     | обман клієнта, а разом із мікророзміткою ще й привід для санкцій.
     */
    $rating = $reviews->isNotEmpty() ? round($reviews->avg('rating'), 1) : null;
    $count = $reviews->count();
@endphp

@if ($reviews->isNotEmpty())
    <x-section :id="'reviews'">
        <div class="mb-5 flex flex-wrap items-baseline gap-3">
            <h2 class="t-h2">{{ $title }}</h2>
            @if ($rating)
                <span class="font-mono text-lg font-bold">{{ str_replace('.', ',', (string) $rating) }}</span>
                <span class="text-star" aria-hidden="true">{{ str_repeat('★', (int) round($rating)) }}</span>
                @if ($count)
                    <span class="text-sm text-text-3">{{ $count }} відгуків</span>
                @endif
            @endif
            @if ($googleUrl)
                <a href="{{ $googleUrl }}" class="ml-auto text-sm font-semibold text-brand">Усі відгуки в Google →</a>
            @endif
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($reviews as $review)
                <figure class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                    <div class="flex items-center justify-between gap-2">
                        <figcaption class="text-sm font-semibold">
                            {{ $review->author }}
                            @if ($review->author_note)
                                <span class="font-normal text-text-3">· {{ $review->author_note }}</span>
                            @endif
                        </figcaption>
                        @if ($review->source === 'google')
                            <span class="text-[11px] font-semibold text-text-3">Google</span>
                        @endif
                    </div>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="text-star" aria-label="Оцінка {{ $review->rating }} з 5">{{ str_repeat('★', $review->rating) }}<span class="text-border-1">{{ str_repeat('★', 5 - $review->rating) }}</span></span>
                        <span class="font-mono text-xs text-text-3">{{ $review->published_at->format('d.m.Y') }}</span>
                    </div>
                    <blockquote class="mt-2 text-sm leading-[22px] text-text-2">{{ $review->body }}</blockquote>
                </figure>
            @endforeach
        </div>
    </x-section>
@endif
