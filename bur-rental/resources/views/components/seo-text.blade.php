@props(['title' => null, 'body'])

{{-- SEO-блок стоїть ПІСЛЯ контенту: спершу товар, потім текст для пошуковика. --}}
@if ($body)
    <x-section>
        <div class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
            @if ($title)
                <h2 class="t-h2 mb-3">{{ $title }}</h2>
            @endif
            @foreach (preg_split('/\n\s*\n/', trim($body)) as $paragraph)
                <p class="mt-3 max-w-[840px] text-[15px] leading-[26px] text-text-2 first:mt-0">{{ $paragraph }}</p>
            @endforeach
        </div>
    </x-section>
@endif
