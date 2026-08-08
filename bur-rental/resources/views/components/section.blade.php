@props(['title' => null, 'lead' => null, 'id' => null, 'action' => null, 'actionUrl' => null])

<section @if ($id) id="{{ $id }}" @endif {{ $attributes->merge(['class' => 'mt-12']) }}>
    @if ($title)
        <div class="mb-5 flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h2 class="t-h2">{{ $title }}</h2>
                @if ($lead)
                    <p class="mt-1 max-w-[680px] text-sm text-text-2">{{ $lead }}</p>
                @endif
            </div>
            @if ($action && $actionUrl)
                <a href="{{ $actionUrl }}" class="text-sm font-semibold text-brand">{{ $action }} →</a>
            @endif
        </div>
    @endif

    {{ $slot }}
</section>
