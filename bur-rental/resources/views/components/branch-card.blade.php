@props(['branch', 'city'])

<div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
    <div class="flex items-baseline justify-between gap-3">
        <h3 class="t-h3">{{ $branch->name }}</h3>
        <span class="rounded-[2px] border border-success-border bg-success-bg px-2 py-0.5 text-[11px] font-semibold text-success-text">
            відчинено до 20:00
        </span>
    </div>
    <p class="mt-1.5 text-sm text-text-2">{{ $branch->address }} · {{ $branch->hours }}</p>
    @if ($branch->phone)
        <div class="mt-1 font-mono text-sm font-semibold">{{ $branch->phone }}</div>
    @endif
    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
        <a href="{{ route('branch', [$city, $branch]) }}" class="font-semibold text-brand">сторінка філії →</a>
        <a href="https://www.google.com/maps/search/{{ urlencode($branch->address) }}" class="text-text-2 underline">маршрут</a>
    </div>
</div>
