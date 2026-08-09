@props(['faqs', 'title' => 'Часті питання'])

@if ($faqs->isNotEmpty())
    <x-section :title="$title">
        {{-- Один відкритий за раз: акордеон, а не полотно тексту. --}}
        <div x-data="{ open: null }" class="overflow-hidden rounded-[12px] border border-border-1 bg-surface-0">
            @foreach ($faqs as $faq)
                <div class="border-t border-surface-2 first:border-t-0" :class="open === {{ $loop->index }} && 'bg-surface-1'">
                    <h3>
                        <button type="button" @click="open = open === {{ $loop->index }} ? null : {{ $loop->index }}"
                                :aria-expanded="open === {{ $loop->index }}"
                                class="flex min-h-11 w-full cursor-pointer items-center justify-between gap-4 px-5 py-3.5 text-left text-[15px] font-medium">
                            {{ $faq->question }}
                            <span class="text-text-3 transition-transform duration-150"
                                  :class="open === {{ $loop->index }} && 'rotate-45'">
                                <x-ui-icon name="plus" class="size-4" />
                            </span>
                        </button>
                    </h3>
                    <div x-show="open === {{ $loop->index }}" x-collapse x-cloak>
                        <p class="px-5 pb-3.5 text-sm leading-[22px] text-text-2">{{ $faq->answer }}</p>
                    </div>
                </div>
            @endforeach
        </div>
    </x-section>

    @push('head')
        @php
            $faqSchema = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $faqs->map(fn ($f) => [
                    '@type' => 'Question',
                    'name' => $f->question,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f->answer],
                ])->all(),
            ];
        @endphp

        <script type="application/ld+json">{!! json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
    @endpush
@endif
