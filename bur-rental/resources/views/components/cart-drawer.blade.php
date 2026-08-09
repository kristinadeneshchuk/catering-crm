{{-- Кошик виїжджає збоку (на мобільному — знизу), а не редіректить на сторінку. --}}
<div x-cloak x-show="$store.booking.drawerOpen" @keydown.escape.window="$store.booking.drawerOpen = false"
     class="fixed inset-0 z-100" role="dialog" aria-modal="true" aria-label="Кошик">
    <div x-show="$store.booking.drawerOpen" x-transition.opacity
         class="absolute inset-0 bg-surface-dark/45" @click="$store.booking.drawerOpen = false"></div>

    <div x-show="$store.booking.drawerOpen"
         x-transition:enter="transition duration-200" x-transition:enter-start="translate-x-full max-sm:translate-y-full max-sm:translate-x-0"
         class="absolute right-0 top-0 flex h-full w-[380px] max-w-full flex-col bg-surface-0 max-sm:inset-x-0 max-sm:top-auto max-sm:h-auto max-sm:max-h-[85vh] max-sm:w-full max-sm:rounded-t-[12px]">
        <div class="flex items-center justify-between border-b border-border-1 px-5 py-4">
            <h2 class="t-h2">Кошик</h2>
            <button type="button" @click="$store.booking.drawerOpen = false" class="cursor-pointer p-2" aria-label="Закрити">
                <x-ui-icon name="close" class="size-5" />
            </button>
        </div>

        <div class="flex-1 overflow-auto px-5 py-4">
            <template x-if="!$store.booking.cart.length">
                <div class="py-10 text-center">
                    <p class="text-sm text-text-2">Порожньо. Оберіть інструмент — дати й філія підставляться самі.</p>
                    <a href="{{ route('catalog.index') }}" class="mt-4 inline-flex h-11 items-center rounded-[6px] bg-brand px-4 text-sm font-semibold text-white no-underline hover:text-white">
                        До каталогу
                    </a>
                </div>
            </template>

            <template x-for="(item, i) in $store.booking.cart" :key="i">
                <div class="flex gap-3 border-b border-border-1 py-3 last:border-0">
                    <div class="size-16 shrink-0 rounded-[6px] bg-surface-2"></div>
                    <div class="flex-1">
                        <div class="text-[11px] text-text-3" x-text="item.brand"></div>
                        <div class="text-sm font-semibold" x-text="item.name"></div>
                        <div class="mt-1 font-mono text-xs text-text-2">
                            <span x-text="item.days"></span> дн. · <span x-text="item.from"></span> — <span x-text="item.to"></span>
                        </div>
                        <div class="text-xs text-text-3" x-text="item.branch"></div>
                        <div class="mt-1.5 flex items-center gap-3">
                            <div class="flex items-center gap-1">
                                <button type="button" class="size-7 cursor-pointer rounded-[6px] border border-border-1" @click="$store.booking.setQty(i, item.qty - 1)">−</button>
                                <span class="w-6 text-center font-mono text-sm" x-text="item.qty"></span>
                                <button type="button" class="size-7 cursor-pointer rounded-[6px] border border-border-1" @click="$store.booking.setQty(i, item.qty + 1)">+</button>
                            </div>
                            <span class="font-mono text-sm font-bold" x-text="(item.price * item.days * item.qty).toLocaleString('uk-UA') + ' ₴'"></span>
                            <button type="button" class="ml-auto cursor-pointer text-xs text-danger" @click="$store.booking.remove(i)">прибрати</button>
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <div x-show="$store.booking.cart.length" class="border-t border-border-1 px-5 py-4">
            <div class="flex justify-between text-sm">
                <span class="text-text-2">Оренда</span>
                <span class="font-mono font-semibold" x-text="$store.booking.total.toLocaleString('uk-UA') + ' ₴'"></span>
            </div>
            {{-- Застава візуально відділена: це не витрата, вона повертається. --}}
            <div class="mt-1 flex justify-between border-t border-border-1 pt-2 text-sm">
                <span class="text-text-2">Застава <span class="text-text-3">(повертається)</span></span>
                <span class="font-mono font-semibold" x-text="$store.booking.deposit.toLocaleString('uk-UA') + ' ₴'"></span>
            </div>
            <a href="{{ route('booking.create') }}"
               class="mt-4 flex h-13 items-center justify-center rounded-[6px] bg-brand text-base font-semibold text-white no-underline hover:bg-brand-hover hover:text-white hover:no-underline">
                Перейти до бронювання
            </a>
        </div>
    </div>
</div>
