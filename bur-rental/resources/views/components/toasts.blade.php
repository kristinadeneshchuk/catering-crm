{{-- Тост на успішну дію: додано в кошик, бронь створена. --}}
<div x-cloak x-show="$store.booking.toast" x-transition
     class="fixed inset-x-0 bottom-24 z-100 flex justify-center px-4 nav:bottom-6">
    <div class="flex items-center gap-2 rounded-[12px] bg-surface-dark px-4 py-3 text-sm font-semibold text-white shadow-[var(--shadow-float)]">
        <x-icon name="check" class="size-4 text-brand-bright" />
        <span x-text="$store.booking.toast"></span>
    </div>
</div>
