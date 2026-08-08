{{-- Тільки мобільний. Тап на «Каталог» виїжджає меню знизу, а не веде на сторінку. --}}
<nav x-data="{ catalog: false }" class="fixed inset-x-0 bottom-0 z-95 px-3 pt-2 pb-[calc(8px+env(safe-area-inset-bottom))] nav:hidden"
     aria-label="Основна навігація">
    <div class="grid grid-cols-5 rounded-[32px] border border-border-1 bg-surface-0 px-1 py-2 shadow-[var(--shadow-float)]">
        <a href="{{ route('home') }}" class="flex min-h-11 flex-col items-center justify-center gap-[3px] text-[11px] font-semibold text-text-2 no-underline hover:text-brand hover:no-underline">
            <x-icon name="home" class="size-[22px]" /> Головна
        </a>
        <button type="button" @click="catalog = true" class="flex min-h-11 cursor-pointer flex-col items-center justify-center gap-[3px] text-[11px] font-semibold text-text-2">
            <x-icon name="grid" class="size-[22px]" /> Каталог
        </button>
        <button type="button" @click="$store.booking.drawerOpen = true" class="flex min-h-11 cursor-pointer flex-col items-center justify-center gap-[3px] text-[11px] font-semibold text-text-2">
            <span class="relative">
                <x-icon name="cart" class="size-[22px]" />
                <span x-show="$store.booking.count" x-cloak x-text="$store.booking.count"
                      class="absolute -right-2.5 -top-1.5 rounded-[8px] bg-brand px-1.5 py-px font-mono text-[10px] font-bold text-white"></span>
            </span>
            Кошик
        </button>
        <a href="{{ route('catalog.index') }}" class="flex min-h-11 flex-col items-center justify-center gap-[3px] text-[11px] font-semibold text-text-2 no-underline hover:text-brand hover:no-underline">
            <x-icon name="heart" class="size-[22px]" /> Обране
        </a>
        <a href="{{ route('contacts') }}" class="flex min-h-11 flex-col items-center justify-center gap-[3px] text-[11px] font-semibold text-text-2 no-underline hover:text-brand hover:no-underline">
            <x-icon name="user" class="size-[22px]" /> Кабінет
        </a>
    </div>

    <div x-show="catalog" x-cloak x-transition.opacity class="fixed inset-0 z-100 bg-surface-dark/45" @click="catalog = false">
        <div class="absolute inset-x-0 bottom-0 max-h-[80vh] overflow-auto rounded-t-[12px] bg-surface-0 p-4" @click.stop>
            <div class="mb-3 flex items-center justify-between">
                <h2 class="t-h2">Каталог</h2>
                <button type="button" @click="catalog = false" class="cursor-pointer p-2" aria-label="Закрити">
                    <x-icon name="close" class="size-5" />
                </button>
            </div>
            <ul class="divide-y divide-border-1">
                @foreach ($menuCategories as $item)
                    <li>
                        <a href="{{ route('category', $item) }}" class="flex min-h-11 items-center justify-between py-2 text-sm font-medium text-text-1 no-underline">
                            {{ $item->name }}
                            <span class="font-mono text-xs text-text-3">{{ $item->products_count }}</span>
                        </a>
                    </li>
                @endforeach
                <li>
                    <a href="{{ route('kits.index') }}" class="flex min-h-11 items-center py-2 text-sm font-semibold text-brand no-underline">
                        Комплекти під задачу →
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
