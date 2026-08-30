<footer class="mt-16 bg-surface-dark py-12 text-text-on-dark">
    <div class="container-bur">
        <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <div class="font-display text-2xl font-bold tracking-[0.02em] text-white uppercase">
                    БУР<span class="text-brand-bright">.</span>
                </div>
                <p class="mt-3 max-w-[280px] text-sm">
                    Прокат будівельного, садового та вимірювального інструменту.
                    {{ $city->name }} і область, доставка щодня.
                </p>
                <div class="mt-4 font-mono text-lg font-semibold text-white">{{ $city->phone }}</div>
                <div class="text-sm">щодня 8:00–20:00</div>
                <div class="mt-3 flex gap-3 text-sm">
                    <a href="https://t.me/burrental" class="text-brand-bright">Telegram</a>
                    <a href="viber://chat?number=%2B380672458080" class="text-brand-bright">Viber</a>
                    <a href="https://wa.me/380672458080" class="text-brand-bright">WhatsApp</a>
                </div>
            </div>

            <nav aria-labelledby="ft-catalog">
                <h2 id="ft-catalog" class="t-caption mb-3 text-white">Каталог</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($menuCategories->take(6) as $item)
                        <li><a href="{{ route('category', $item) }}" class="text-text-on-dark hover:text-white">{{ $item->name }}</a></li>
                    @endforeach
                    <li><a href="{{ route('kits.index') }}" class="text-brand-bright">Комплекти під задачу</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="ft-clients">
                <h2 id="ft-clients" class="t-caption mb-3 text-white">Клієнтам</h2>
                <ul class="space-y-2 text-sm">
                    <li><a href="{{ route('terms') }}" class="text-text-on-dark hover:text-white">Умови оренди</a></li>
                    <li><a href="{{ route('delivery') }}" class="text-text-on-dark hover:text-white">Доставка й оплата</a></li>
                    <li><a href="{{ route('returns') }}" class="text-text-on-dark hover:text-white">Повернення застави</a></li>
                    <li><a href="{{ route('b2b') }}" class="text-text-on-dark hover:text-white">Умови для юросіб (B2B)</a></li>
                    <li><a href="{{ route('blog.index') }}" class="text-text-on-dark hover:text-white">Статті</a></li>
                    <li><a href="{{ route('contacts') }}" class="text-text-on-dark hover:text-white">Контакти</a></li>
                </ul>
            </nav>

            <nav aria-labelledby="ft-branches">
                <h2 id="ft-branches" class="t-caption mb-3 text-white">Філії</h2>
                <ul class="space-y-2 text-sm">
                    @foreach ($city->branches as $branch)
                        <li>
                            <a href="{{ route('branch', [$city, $branch]) }}" class="text-text-on-dark hover:text-white">
                                {{ $city->name }} — {{ $branch->name }}
                            </a>
                            <div class="text-xs text-text-on-dark/70">{{ $branch->address }}</div>
                        </li>
                    @endforeach
                </ul>

                <h2 class="t-caption mb-2 mt-5 text-white">Мова</h2>
                <div class="flex gap-1 text-sm">
                    <span class="rounded-[2px] bg-white/10 px-2 py-0.5 font-semibold text-white">UA</span>
                    {{-- Перемикач передбачений дизайном, але RU-версії ще немає --}}
                    <span class="px-2 py-0.5 text-text-on-dark/50" title="Скоро">RU</span>
                </div>
            </nav>
        </div>

        <div class="mt-10 flex flex-wrap gap-x-6 gap-y-2 border-t border-white/10 pt-6 text-xs">
            <span>© {{ date('Y') }} ТОВ «БУР Прокат» · ЄДРПОУ 43215678 · вул. Здолбунівська 7Г, Київ, 02081</span>
            <a href="{{ route('terms') }}" class="text-text-on-dark underline">Договір оферти</a>
            <a href="{{ route('terms') }}" class="text-text-on-dark underline">Політика конфіденційності</a>
        </div>
    </div>
</footer>
