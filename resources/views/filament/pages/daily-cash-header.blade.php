@php
    /** @var array $summary */
    $fmt = fn ($v) => number_format((float) $v, 0, '.', ' ');
@endphp

<div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">
            🧾 Каса за день
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-gray-500 dark:text-gray-400">Дата:</label>
            <input type="date" wire:model.live="cashDate"
                   class="rounded-md border-gray-300 bg-white px-2 py-1 text-sm dark:border-white/10 dark:bg-gray-800 dark:text-gray-100" />
        </div>
    </div>

    {{-- Рахунки: залишки прямо зараз (не на дату — це поточний стан кас) --}}
    <div class="mb-4 rounded-lg bg-gray-50 p-3 dark:bg-white/5">
        <div class="mb-1 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
            Залишки на рахунках (зараз)
        </div>
        <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
            @foreach ($summary['accounts']['rows'] as $acc)
                <div class="whitespace-nowrap">
                    <span class="text-gray-500 dark:text-gray-400">{{ $acc['name'] }}:</span>
                    <span class="font-semibold {{ $acc['balance'] < 0 ? 'text-rose-600' : 'text-gray-900 dark:text-gray-100' }}">
                        {{ $fmt($acc['balance']) }} ₴
                    </span>
                </div>
            @endforeach
            <div class="whitespace-nowrap border-l border-gray-300 pl-4 dark:border-white/20">
                <span class="text-gray-500 dark:text-gray-400">Разом:</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ $fmt($summary['accounts']['total']) }} ₴</span>
            </div>
        </div>

        {{-- Розбивка приходу дня по рахунках — для звіряння готівки/картки --}}
        @if (! empty($summary['incomeByAccount']))
            <div class="mt-2 border-t border-gray-200 pt-2 dark:border-white/10">
                <div class="mb-1 text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
                    Прийшло сьогодні
                </div>
                <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                    @foreach ($summary['incomeByAccount'] as $row)
                        <div class="whitespace-nowrap">
                            <span class="text-gray-500 dark:text-gray-400">{{ $row['name'] }}:</span>
                            <span class="font-semibold text-emerald-700 dark:text-emerald-300">
                                +{{ $fmt($row['total']) }} ₴
                            </span>
                            <span class="text-xs text-gray-400">({{ $row['count'] }})</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Рух дня — 4 плитки --}}
    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-500/30 dark:bg-emerald-500/10">
            <div class="text-xs text-emerald-800 dark:text-emerald-300">📥 Прихід дня</div>
            <div class="mt-1 text-lg font-bold text-emerald-700 dark:text-emerald-300">+{{ $fmt($summary['income']['sum']) }} ₴</div>
            <div class="text-xs text-emerald-700/70 dark:text-emerald-300/70">{{ $summary['income']['count'] }} оплат</div>
        </div>
        <div class="rounded-lg border border-rose-200 bg-rose-50 p-3 dark:border-rose-500/30 dark:bg-rose-500/10">
            <div class="text-xs text-rose-800 dark:text-rose-300">📤 Витрати дня</div>
            <div class="mt-1 text-lg font-bold text-rose-700 dark:text-rose-300">−{{ $fmt($summary['expenses']['sum']) }} ₴</div>
            <div class="text-xs text-rose-700/70 dark:text-rose-300/70">{{ $summary['expenses']['count'] }} шт.</div>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
            <div class="text-xs text-amber-800 dark:text-amber-300">💰 Виплати ЗП</div>
            <div class="mt-1 text-lg font-bold text-amber-700 dark:text-amber-300">−{{ $fmt($summary['salaries']['sum']) }} ₴</div>
            <div class="text-xs text-amber-700/70 dark:text-amber-300/70">{{ $summary['salaries']['count'] }} шт.</div>
        </div>
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 dark:border-slate-500/30 dark:bg-slate-500/10">
            <div class="text-xs text-slate-800 dark:text-slate-300">🏭 Закупівлі</div>
            <div class="mt-1 text-lg font-bold text-slate-700 dark:text-slate-300">−{{ $fmt($summary['purchases']['sum']) }} ₴</div>
            <div class="text-xs text-slate-700/70 dark:text-slate-300/70">{{ $summary['purchases']['count'] }} шт.</div>
        </div>
    </div>

    {{-- ФОП і неоплачені --}}
    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div class="rounded-lg border border-indigo-200 bg-indigo-50 p-3 dark:border-indigo-500/30 dark:bg-indigo-500/10">
            <div class="flex items-center justify-between">
                <div class="text-xs text-indigo-800 dark:text-indigo-300">📊 ФОП нараховано за день</div>
                <div class="text-lg font-bold text-indigo-700 dark:text-indigo-300">−{{ $fmt($summary['fop']['total']) }} ₴</div>
            </div>
            <div class="mt-1 flex flex-wrap gap-x-4 gap-y-0.5 text-xs text-indigo-700/80 dark:text-indigo-300/80">
                <span>Кухня: <b>{{ $fmt($summary['fop']['kitchen']) }} ₴</b></span>
                <span>Курʼєри: <b>{{ $fmt($summary['fop']['couriers']) }} ₴</b></span>
                <span>Інше: <b>{{ $fmt($summary['fop']['other']) }} ₴</b></span>
            </div>
        </div>

        <div x-data="{ open: false }"
             class="rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-500/30 dark:bg-orange-500/10">
            <button type="button" @click="open = !open"
                    class="flex w-full items-center justify-between">
                <div class="text-xs text-orange-800 dark:text-orange-300">
                    ⚠️ Не оплатили сьогодні
                    @if ($summary['unpaid']['count'] > 0)
                        · <b>{{ $summary['unpaid']['count'] }}</b> клієнт(и)
                    @endif
                </div>
                <div class="text-lg font-bold text-orange-700 dark:text-orange-300">
                    {{ $fmt($summary['unpaid']['sum']) }} ₴
                    @if ($summary['unpaid']['count'] > 0)
                        <span class="ml-1 text-xs" x-text="open ? '▲' : '▼'"></span>
                    @endif
                </div>
            </button>
            @if ($summary['unpaid']['count'] > 0)
                <div x-show="open" x-transition class="mt-2 max-h-56 overflow-auto text-xs">
                    <table class="w-full">
                        <tbody>
                        @foreach ($summary['unpaid']['rows'] as $row)
                            <tr class="border-t border-orange-200/50 dark:border-orange-500/20">
                                <td class="py-1 pr-2">
                                    <a href="{{ \App\Filament\Resources\ClientResource::getUrl('edit', ['record' => $row['id']]) }}"
                                       class="font-medium text-orange-800 hover:underline dark:text-orange-200">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td class="py-1 pr-2 text-orange-700/70 dark:text-orange-300/70">{{ $row['phone'] }}</td>
                                <td class="py-1 text-right font-semibold text-rose-700 dark:text-rose-300">
                                    {{ $fmt($row['debt']) }} ₴
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
