@props(['product', 'branches' => null, 'from' => null, 'to' => null])

@php
    /*
     | Бейдж наявності. Ціна, наявність і філія — три числа, які клієнт
     | зіставляє одночасно, тому бейдж завжди поруч із ціною.
     */
    $from ??= now()->toDateString();
    $to ??= now()->toDateString();

    $list = $branches ?? $product->branches;
    $free = $list->filter(fn ($branch) => $product->isFreeAt($branch, $from, $to));

    [$tone, $text] = match (true) {
        $free->count() >= 2 => ['success', 'Є у '.$free->count().' філіях'],
        $free->count() === 1 => ['success', 'Вільний · '.$free->first()->name],
        $list->isEmpty() => ['info', 'Під замовлення'],
        default => ['danger', 'Зайнятий на ці дати'],
    };

    $classes = [
        'success' => 'bg-success-bg text-success-text border-success-border',
        'warning' => 'bg-warning-bg text-warning-text border-warning-border',
        'danger' => 'bg-danger-bg text-danger-text border-danger-border',
        'info' => 'bg-surface-1 text-info border-border-1',
    ][$tone];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-1 rounded-[2px] border px-2 py-0.5 text-[11px] font-semibold $classes"]) }}>
    {{ $text }}
</span>
