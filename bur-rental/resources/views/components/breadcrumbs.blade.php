@props(['items' => []])

{{-- Хлібні крихти + JSON-LD: сторінки категорій і PDP — вхід усього SEO-трафіку. --}}
<nav aria-label="Хлібні крихти" class="py-3 text-[13px] text-text-3">
    <ol class="flex flex-wrap items-center gap-1.5">
        @foreach ($items as $label => $url)
            <li class="flex items-center gap-1.5">
                @if ($loop->last || ! $url)
                    <span class="text-text-2">{{ $label }}</span>
                @else
                    <a href="{{ $url }}" class="text-text-3 hover:text-brand">{{ $label }}</a>
                    <span aria-hidden="true">/</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

@push('head')
    @php
        // json_encode замість директиви @json: Blade ріже аргумент директиви
        // на першому «=>» усередині вкладених дужок.
        $crumbs = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->values()
                ->map(fn ($url, $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'name' => array_keys($items)[$i],
                    'item' => $url ?: url()->current(),
                ])->all(),
        ];
    @endphp

    <script type="application/ld+json">{!! json_encode($crumbs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endpush
