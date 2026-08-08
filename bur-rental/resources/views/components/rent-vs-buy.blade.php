@props(['rentWeek' => 1680, 'ownYear' => 8800, 'note' => null])

{{-- Наскрізний блок: він знімає головне заперечення «дешевше купити». --}}
<x-section>
    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
        <h2 class="t-h2">Оренда чи купівля?</h2>
        <p class="mt-1 max-w-[640px] text-sm text-text-2">
            {{ $note ?? 'Якщо інструмент потрібен до 30 днів на рік — оренда вигідніша. Ремонт, сервіс і зберігання — наші.' }}
        </p>

        <div class="mt-5 grid gap-4 sm:grid-cols-3">
            <div class="rounded-[8px] border border-brand bg-brand-tint p-4">
                <div class="t-price text-brand">{{ number_format($rentWeek, 0, ',', ' ') }} ₴</div>
                <div class="mt-1 text-[13px] text-text-2">тиждень оренди</div>
            </div>
            <div class="rounded-[8px] border border-border-1 p-4">
                <div class="t-price">~{{ number_format($ownYear, 0, ',', ' ') }} ₴</div>
                <div class="mt-1 text-[13px] text-text-2">перший рік володіння</div>
            </div>
            <div class="rounded-[8px] border border-border-1 p-4">
                <div class="t-price">0 ₴</div>
                <div class="mt-1 text-[13px] text-text-2">ремонт і зберігання</div>
            </div>
        </div>
    </div>
</x-section>
