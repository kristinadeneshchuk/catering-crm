<x-filament-widgets::widget>
    <x-filament::section>
        <div x-data="{ url: @js($this->getUrl()), copied: false }" class="fi-section-content-ctn">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex items-start gap-3">
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary-500/10 text-primary-600 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-link" class="h-5 w-5" />
                    </div>
                    <div>
                        <div class="text-base font-semibold text-gray-950 dark:text-white">
                            Меню для клієнтів
                        </div>
                        <div class="text-sm text-gray-500 dark:text-gray-400">
                            Постійне посилання: меню на 3 дні з КБЖУ під будь-який калораж. Надсилайте клієнту під час консультації.
                        </div>
                        <a :href="url" x-text="url" target="_blank"
                           class="mt-1 block break-all text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"></a>
                    </div>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <x-filament::button
                        color="gray"
                        icon="heroicon-m-clipboard-document"
                        x-on:click="navigator.clipboard.writeText(url); copied = true; setTimeout(() => copied = false, 1500)">
                        <span x-show="!copied">Копіювати</span>
                        <span x-show="copied" x-cloak>Скопійовано ✓</span>
                    </x-filament::button>

                    <x-filament::button
                        tag="a"
                        :href="$this->getUrl()"
                        target="_blank"
                        icon="heroicon-m-arrow-top-right-on-square">
                        Відкрити
                    </x-filament::button>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
