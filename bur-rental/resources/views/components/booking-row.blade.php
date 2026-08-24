@props(['booking'])

@php
    // Кольором позначаємо лише те, що вимагає дії: прострочення й «сьогодні».
    $left = $booking->returns_in;
    $live = in_array($booking->status, ['new', 'confirmed', 'issued'], true);

    [$tone, $note] = match (true) {
        ! $live => ['muted', null],
        $left < 0 => ['danger', 'Прострочення '.abs($left).' дн. — рахується за базовим тарифом'],
        $left === 0 => ['warning', 'Повернення сьогодні до 18:00'],
        $left <= 2 => ['warning', 'Повернення через '.$left.' дн.'],
        default => ['ok', 'Повернення через '.$left.' дн.'],
    };

    $noteClass = match ($tone) {
        'danger' => 'text-danger-text',
        'warning' => 'text-warning-text',
        default => 'text-text-2',
    };
@endphp

<article class="rounded-[12px] border border-border-1 bg-surface-0 p-4">
    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
        <a href="{{ route('cabinet.booking', $booking) }}" class="font-mono text-[15px] font-bold text-text-1 no-underline hover:text-brand">
            {{ $booking->number }}
        </a>
        <span class="rounded-[2px] bg-surface-1 px-2 py-0.5 text-[11px] font-semibold text-text-2">
            {{ $booking->status_label }}
        </span>
    </div>

    <p class="mt-1.5 font-mono text-[13px] text-text-2">
        {{ $booking->date_from->format('d.m.Y') }} — {{ $booking->date_to->format('d.m.Y') }}
        · {{ $booking->days }} дн.
        @if ($booking->branch)
            · {{ $booking->branch->name }}
        @endif
    </p>

    @if ($note)
        <p class="mt-1 text-[13px] font-semibold {{ $noteClass }}">{{ $note }}</p>
    @endif

    <ul class="mt-3 space-y-1 text-sm text-text-2">
        @foreach ($booking->items as $item)
            <li class="flex justify-between gap-4">
                <span>{{ $item->title }}@if ($item->qty > 1) <span class="font-mono text-xs">×{{ $item->qty }}</span>@endif</span>
                <span class="shrink-0 font-mono">{{ number_format($item->total, 0, ',', ' ') }} ₴</span>
            </li>
        @endforeach
    </ul>

    <div class="mt-3 flex flex-wrap items-baseline justify-between gap-2 border-t border-border-1 pt-3">
        <span class="text-[13px] text-text-2">
            Оренда й послуги
            @if ($booking->deposit_total)
                <span class="block text-[11px] text-text-3">
                    + застава {{ number_format($booking->deposit_total, 0, ',', ' ') }} ₴, повертається
                </span>
            @endif
        </span>
        <span class="font-mono text-lg font-bold">
            {{ number_format($booking->payable - $booking->deposit_total, 0, ',', ' ') }} ₴
        </span>
    </div>
</article>
