@props(['title' => null, 'body'])

{{-- SEO-блок стоїть ПІСЛЯ контенту: спершу товар, потім текст для пошуковика. --}}
@if ($body)
    <x-section>
        <div class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
            @if ($title)
                <h2 class="t-h2 mb-3">{{ $title }}</h2>
            @endif
            {{-- Абзац, що починається з «## », — підзаголовок. Довгий текст без
                 них не читають, а H3 ще й підказують пошуковику структуру. --}}
            @foreach (preg_split('/\n\s*\n/', trim($body)) as $paragraph)
                @if (str_starts_with($paragraph, '## '))
                    <h3 class="mt-6 text-[17px] font-semibold text-text-1 first:mt-0">{{ Str::after($paragraph, '## ') }}</h3>
                @else
                    <p class="mt-3 max-w-[840px] text-[15px] leading-[26px] text-text-2 first:mt-0">{{ $paragraph }}</p>
                @endif
            @endforeach
        </div>
    </x-section>
@endif
