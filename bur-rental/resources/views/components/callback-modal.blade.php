@props(['context' => null])

{{-- «Передзвоніть мені» — другий за важливістю CTA після броні. --}}
<div x-data="{ open: false, sent: {{ session('lead') ? 'true' : 'false' }} }"
     x-init="if (sent) open = true"
     @callback-open.window="open = true; sent = false"
     @keydown.escape.window="open = false">
    <div x-show="open" x-cloak x-transition.opacity class="fixed inset-0 z-100 flex items-center justify-center bg-surface-dark/45 p-4"
         @click="open = false" role="dialog" aria-modal="true" aria-labelledby="cb-title">
        <div class="w-full max-w-[420px] rounded-[12px] bg-surface-0 p-6 shadow-[var(--shadow-float)]" @click.stop>
            <template x-if="!sent">
                <div>
                    <div class="flex items-start justify-between gap-4">
                        <h2 id="cb-title" class="t-h2">Передзвоніть мені</h2>
                        <button type="button" @click="open = false" class="cursor-pointer p-1" aria-label="Закрити">
                            <x-icon name="close" class="size-5" />
                        </button>
                    </div>
                    <p class="mt-2 text-sm text-text-2">
                        Передзвонимо за 15 хвилин у робочий час (8:00–20:00).
                    </p>

                    <form method="post" action="{{ route('leads.store') }}" class="mt-4 space-y-3">
                        @csrf
                        <input type="hidden" name="kind" value="callback">
                        <input type="hidden" name="context" value="{{ $context ?? request()->path() }}">

                        <x-field name="name" label="Як до вас звертатись" />
                        <x-field name="phone" label="Телефон" type="tel" placeholder="+380 __ ___ __ __" required />

                        <button type="submit" class="h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                            Чекаю дзвінка
                        </button>
                    </form>
                </div>
            </template>

            <template x-if="sent">
                <div class="py-4 text-center">
                    <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-success-bg text-success">
                        <x-icon name="check" class="size-6" />
                    </div>
                    <h2 class="t-h2 mt-3">Заявку прийнято</h2>
                    <p class="mt-2 text-sm text-text-2">{{ session('lead') ?: 'Менеджер набере вас протягом 15 хвилин.' }}</p>
                    <button type="button" @click="open = false" class="mt-4 h-11 cursor-pointer rounded-[6px] border border-text-1 px-5 text-sm font-semibold">
                        Готово
                    </button>
                </div>
            </template>
        </div>
    </div>
</div>
