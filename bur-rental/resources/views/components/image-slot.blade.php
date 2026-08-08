@props(['ratio' => '4/3', 'label' => 'фото', 'eager' => false])

{{--
    Плейсхолдер під реальне фото. Пропорція зарезервована завжди — це вимога
    нульового CLS: коли приїде справжнє фото, сторінка не стрибне.
--}}
<div {{ $attributes->merge(['class' => 'flex items-end overflow-hidden bg-surface-2 p-2']) }}
     style="aspect-ratio: {{ $ratio }}">
    <span class="text-[10px] text-text-3">{{ $label }}</span>
</div>
