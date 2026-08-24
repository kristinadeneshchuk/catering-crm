@props(['product', 'branches' => null, 'from' => null, 'to' => null])

@php
    // Кнопка веде на PDP з уже обраними датами й філією — щоб не обирати двічі.
    $query = array_filter(['from' => $from, 'to' => $to, 'branch' => request('branch')]);
    $url = route('product', $product).($query ? '?'.http_build_query($query) : '');
    $minTier = $product->min_price_tier;
@endphp

<article class="group flex flex-col overflow-hidden rounded-[12px] border border-border-1 bg-surface-0">
    <a href="{{ $url }}" class="block no-underline hover:no-underline" tabindex="-1" aria-hidden="true">
        <x-image-slot :label="$product->brand->name.' '.$product->name" />
    </a>

    <div class="flex flex-1 flex-col p-3.5">
        <div class="text-[11px] text-text-3">{{ $product->brand->name }}</div>

        <h3 class="mt-0.5 text-[15px] font-semibold leading-snug">
            <a href="{{ $url }}" class="text-text-1 no-underline hover:text-brand hover:no-underline">{{ $product->name }}</a>
        </h3>

        @if ($product->key_specs)
            <div class="mt-1.5 font-mono text-xs text-text-2">{{ implode(' · ', $product->key_specs) }}</div>
        @endif

        <div class="mt-2.5">
            <x-availability-badge :product="$product" :branches="$branches" :from="$from" :to="$to" />
        </div>

        <div class="mt-3 flex items-baseline gap-1.5">
            <span class="text-xs text-text-3">від</span>
            <span class="font-mono text-lg font-bold">{{ number_format($product->min_price, 0, ',', ' ') }} ₴</span>
            <span class="text-xs text-text-3">/день</span>
        </div>
        <div class="text-[11px] text-text-3">при оренді {{ mb_strtolower($minTier?->label ?? 'від 7 днів') }}</div>

        <div class="mt-3 flex items-center gap-2">
            <a href="{{ $url }}"
               class="flex h-11 flex-1 items-center justify-center rounded-[6px] bg-brand text-sm font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                Забронювати
            </a>
            <button type="button" @click="$store.favourites.toggle({{ $product->id }})"
                    :aria-pressed="$store.favourites.has({{ $product->id }})"
                    :class="$store.favourites.has({{ $product->id }}) ? 'border-brand text-brand' : 'border-border-1 text-text-3'"
                    class="inline-flex size-11 cursor-pointer items-center justify-center rounded-[6px] border"
                    title="В обране">
                <x-ui-icon name="heart" class="size-[18px]" />
            </button>
            <button type="button" @click="$store.booking.toggleCompare({{ $product->id }})"
                    :aria-pressed="$store.booking.inCompare({{ $product->id }})"
                    :class="$store.booking.inCompare({{ $product->id }}) ? 'border-brand text-brand' : 'border-border-1 text-text-3'"
                    class="inline-flex size-11 cursor-pointer items-center justify-center rounded-[6px] border"
                    title="Порівняти">
                <x-ui-icon name="compare" class="size-[18px]" />
            </button>
        </div>
    </div>
</article>
