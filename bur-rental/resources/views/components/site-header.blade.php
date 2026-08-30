{{--
    Хедер. Порядок елементів не випадковий: каталог і пошук — ліворуч, бо ними
    користуються, телефон і кошик — праворуч. На мобільному пошук іде окремим
    рядком, а «Каталог» ховається: його роль бере нижня навігація.
--}}
<header
    x-data="{ menu: false, cities: false }"
    @keydown.escape.window="menu = false; cities = false"
    class="sticky top-0 z-90 border-b border-border-1 bg-surface-0"
>
    <div class="container-bur flex h-18 flex-wrap items-center gap-5 nav:h-18 max-nav:h-auto max-nav:py-[10px]">
        <a href="{{ route('home') }}"
           class="font-display text-2xl font-bold tracking-[0.02em] text-text-1 uppercase no-underline hover:no-underline">
            БУР<span class="text-brand">.</span>
        </a>

        {{-- Селектор міста: змінює телефони, філії та доставку на всіх сторінках --}}
        <div class="relative">
            <button type="button" @click="cities = !cities" :aria-expanded="cities"
                    class="flex h-9 cursor-pointer items-center gap-1.5 rounded-[6px] px-2.5 text-sm font-medium text-text-1 hover:bg-surface-1">
                <x-ui-icon name="pin" class="size-3.5 text-brand" />
                {{ $city->name }}
                <x-ui-icon name="chevron-down" class="size-3 text-text-3" />
            </button>

            <div x-show="cities" x-cloak @click.outside="cities = false" x-transition.opacity
                 class="absolute left-0 top-full z-50 mt-1 w-52 rounded-[12px] border border-border-1 bg-surface-0 p-1.5 shadow-[var(--shadow-float)]">
                @foreach ($allCities as $option)
                    <a href="{{ route('city', $option) }}"
                       class="flex items-center justify-between rounded-[6px] px-3 py-2 text-sm text-text-1 no-underline hover:bg-surface-1 hover:no-underline">
                        {{ $option->name }}
                        <span class="font-mono text-xs text-text-3">{{ $option->phone }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        <button type="button" @click="menu = !menu" :aria-expanded="menu"
                class="flex h-11 shrink-0 cursor-pointer items-center gap-2 rounded-[8px] bg-brand px-4 text-sm font-semibold text-white hover:bg-brand-hover max-nav:hidden">
            <x-ui-icon name="menu" class="size-4" />
            Каталог
        </button>

        <form action="{{ route('search') }}" method="get"
              class="flex h-11 max-w-[520px] flex-1 items-center gap-2.5 rounded-[8px] border-2 border-brand bg-surface-0 pl-3.5 pr-1 max-nav:order-5 max-nav:max-w-none max-nav:basis-full">
            <label for="site-search" class="sr-only">Пошук інструменту</label>
            <input id="site-search" type="search" name="q" value="{{ request('q') }}"
                   placeholder="Модель, категорія або задача…"
                   class="h-full flex-1 bg-transparent text-[15px] text-text-1 outline-none placeholder:text-text-3">
            <button type="submit" aria-label="Знайти"
                    class="inline-flex h-9 w-13 shrink-0 cursor-pointer items-center justify-center rounded-[6px] bg-brand text-white hover:bg-brand-hover">
                <x-ui-icon name="search" class="size-[17px]" />
            </button>
        </form>

        <div class="ml-auto flex items-center gap-5">
            <div class="text-right max-xs:hidden">
                <div class="font-mono text-[15px] font-semibold">{{ $city->phone }}</div>
                <button type="button" @click="$dispatch('callback-open')"
                        class="cursor-pointer text-xs text-brand underline-offset-2 hover:underline">
                    передзвоніть мені
                </button>
            </div>

            {{-- Обране й кабінет на десктопі: на мобільному вони в нижній навігації --}}
            <a href="{{ route('favourites') }}"
               class="flex items-center gap-1.5 text-sm font-medium text-text-1 no-underline hover:text-brand hover:no-underline max-nav:hidden">
                <span class="relative">
                    <x-ui-icon name="heart" class="size-5" />
                    <span x-show="$store.favourites.count" x-cloak x-text="$store.favourites.count"
                          class="absolute -right-2 -top-1.5 rounded-[8px] bg-brand px-1.5 py-px font-mono text-[10px] font-bold text-white"></span>
                </span>
                Обране
            </a>

            <a href="{{ route('cabinet') }}"
               class="flex items-center gap-1.5 text-sm font-medium text-text-1 no-underline hover:text-brand hover:no-underline max-nav:hidden">
                <x-ui-icon name="user" class="size-5" />
                {{ auth('client')->check() ? 'Кабінет' : 'Увійти' }}
            </a>

            <button type="button" @click="$store.booking.drawerOpen = true"
                    class="flex cursor-pointer items-center gap-1.5 text-sm font-medium text-text-1">
                <span class="relative">
                    <x-ui-icon name="cart" class="size-5" />
                    <span x-show="$store.booking.count" x-cloak x-text="$store.booking.count"
                          class="absolute -right-2 -top-1.5 rounded-[8px] bg-brand px-1.5 py-px font-mono text-[10px] font-bold text-white"></span>
                </span>
                Кошик
            </button>
        </div>
    </div>

    {{-- Мега-меню: максимум два рівні вкладеності, інакше в ньому губляться --}}
    <div x-show="menu" x-cloak x-transition.opacity
         class="absolute inset-x-0 top-full z-80 max-h-[calc(100vh-8rem)] overflow-auto border-b border-border-1 bg-surface-0"
         @click.outside="menu = false">
        <div class="container-bur grid gap-x-6 gap-y-1 py-6 [grid-template-columns:repeat(auto-fill,minmax(230px,1fr))]">
            @foreach ($menuCategories as $item)
                <a href="{{ route('category', $item) }}"
                   class="flex items-baseline justify-between rounded-[6px] px-3 py-2.5 text-sm font-medium text-text-1 no-underline hover:bg-surface-1 hover:no-underline">
                    {{ $item->name }}
                    <span class="font-mono text-xs text-text-3">{{ $item->products_count }}</span>
                </a>
            @endforeach

            <a href="{{ route('kits.index') }}"
               class="flex items-baseline justify-between rounded-[6px] px-3 py-2.5 text-sm font-semibold text-brand no-underline hover:bg-surface-1 hover:no-underline">
                Комплекти під задачу →
            </a>

            <a href="{{ route('blog.index') }}"
               class="flex items-baseline justify-between rounded-[6px] px-3 py-2.5 text-sm font-semibold text-brand no-underline hover:bg-surface-1 hover:no-underline">
                Як зробити роботу →
            </a>
        </div>
    </div>
</header>
