@props(['name', 'label', 'type' => 'text', 'value' => null, 'placeholder' => null, 'required' => false, 'help' => null])

@php
    // Сторінки помилок рендеряться повз ShareErrorsFromSession — там $errors немає.
    $errors ??= new \Illuminate\Support\ViewErrorBag;
@endphp

<div>
    <label for="f-{{ $name }}" class="mb-1 block text-[13px] font-medium text-text-2">
        {{ $label }}@if ($required)<span class="text-danger"> *</span>@endif
    </label>

    <input id="f-{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           value="{{ old($name, $value) }}" placeholder="{{ $placeholder }}"
           @required($required)
           {{ $attributes->merge([
               'class' => 'h-11 w-full rounded-[6px] border bg-surface-0 px-3 text-[15px] outline-none focus:border-brand '
                   .($errors->has($name) ? 'border-danger' : 'border-border-1'),
           ]) }}>

    @error($name)
        <p class="mt-1 text-[13px] text-danger-text">{{ $message }}</p>
    @enderror

    @if ($help && ! $errors->has($name))
        <p class="mt-1 text-[13px] text-text-3">{{ $help }}</p>
    @endif
</div>
