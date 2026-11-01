@props([
    'image' => null,             // App\Models\ProductImage|null
    'alt' => '',
    'ratio' => '4/3',
    'size' => 'md',
    'sizes' => '(max-width: 640px) 50vw, 300px',
    'eager' => false,
])

{{--
    Фото товару з тією самою зарезервованою пропорцією, що й заглушка, —
    поява фото не зсуває сторінку (нульовий CLS). object-contain на білому:
    фото виробників зазвичай на білому тлі й різних пропорцій.
--}}
@if ($image)
    <div {{ $attributes->merge(['class' => 'overflow-hidden bg-white']) }} style="aspect-ratio: {{ $ratio }}">
        <img src="{{ $image->url($size) }}"
             srcset="{{ $image->srcset() }}"
             sizes="{{ $sizes }}"
             alt="{{ $alt }}"
             @if ($image->width) width="{{ $image->width }}" height="{{ $image->height }}" @endif
             loading="{{ $eager ? 'eager' : 'lazy' }}"
             @if ($eager) fetchpriority="high" @endif
             decoding="async"
             class="size-full object-contain p-2">
    </div>
@else
    <x-image-slot :label="$alt" :ratio="$ratio" {{ $attributes }} />
@endif
