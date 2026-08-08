@props(['city', 'title' => 'Оренда у вашому районі', 'districts' => null])

@php $districts ??= $city->districts; @endphp

@if ($districts->isNotEmpty())
    <x-section :title="$title">
        <div class="flex flex-wrap gap-2">
            @foreach ($districts as $district)
                <a href="{{ route('district', [$city, $district]) }}"
                   class="inline-flex min-h-11 items-center rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm text-text-1 no-underline hover:border-brand hover:text-brand hover:no-underline">
                    {{ $district->name }}
                </a>
            @endforeach
        </div>
    </x-section>
@endif
