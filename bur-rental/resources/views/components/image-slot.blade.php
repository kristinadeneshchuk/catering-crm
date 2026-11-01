@props(['ratio' => '4/3', 'label' => 'фото', 'eager' => false, 'src' => null])

{{--
    Фото або плейсхолдер під нього. Пропорція зарезервована завжди — це вимога
    нульового CLS: сторінка не стрибає ні під час завантаження, ні без фото.
    object-contain: на знімках виробника інструмент не можна обрізати.
--}}
@if ($src)
    <div {{ $attributes->merge(['class' => 'overflow-hidden bg-white p-2']) }}
         style="aspect-ratio: {{ $ratio }}">
        <img src="{{ $src }}" alt="{{ $label }}" decoding="async"
             loading="{{ $eager ? 'eager' : 'lazy' }}"
             class="h-full w-full object-contain">
    </div>
@else
    <div {{ $attributes->merge(['class' => 'flex items-end overflow-hidden bg-surface-2 p-2']) }}
         style="aspect-ratio: {{ $ratio }}">
        <span class="text-[10px] text-text-3">{{ $label }}</span>
    </div>
@endif
