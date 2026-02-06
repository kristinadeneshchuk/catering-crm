<x-filament-panels::page>
    <div class="no-print">
        <x-filament::section>
            <form wire:submit.prevent="calculate">
                {{ $this->form }}
            </form>
        </x-filament::section>
    </div>

    @if(empty($shoppingList))
        <div class="p-6 bg-success-500/10 text-success-500 rounded-xl border border-success-500/20 text-center shadow-sm">
            <span class="text-2xl">✅</span> 
            <div class="font-bold mt-2">Усе є на складі!</div>
            <div>На обрану дату купувати нічого не потрібно.</div>
        </div>
    @else
        <x-filament::section>
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold">Потрібно купити:</h2>
                <x-filament::button icon="heroicon-m-printer" color="gray" onclick="window.print()" class="no-print">
                    Друкувати
                </x-filament::button>
            </div>
            
            <div class="overflow-x-auto border border-gray-200 dark:border-white/10 rounded-lg">
                <table class="w-full text-left text-sm">
                    {{-- Шапка таблиці адаптована під Dark Mode --}}
                    <thead class="bg-gray-50 dark:bg-white/5 text-gray-500 dark:text-gray-400 uppercase font-bold">
                        <tr>
                            <th class="px-4 py-3">Продукт</th>
                            <th class="px-4 py-3 text-right">Треба (Брутто)</th>
                            <th class="px-4 py-3 text-right">На складі</th>
                            <th class="px-4 py-3 text-right text-danger-600 dark:text-danger-400">Купити</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                        @foreach($shoppingList as $row)
                            @php
                                $format = function($val, $unit) {
                                    if ($val >= 1000 && in_array($unit, ['г', 'кг', 'мл', 'л'])) {
                                        $label = in_array($unit, ['г', 'кг']) ? 'кг' : 'л';
                                        return "<strong>" . number_format($val/1000, 2, '.', '') . "</strong> " . $label;
                                    }
                                    return "<strong>" . round($val) . "</strong> " . $unit;
                                };
                            @endphp
                            {{-- Виправлено:hover:bg тепер адаптивний, текст не пропадає --}}
                            <tr class="hover:bg-gray-500/5 transition-colors">
                                <td class="px-4 py-3 font-bold text-gray-900 dark:text-white">
                                    {{ $row['name'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-600 dark:text-gray-400">
                                    {!! $format($row['need'], $row['unit']) !!}
                                </td>
                                <td class="px-4 py-3 text-right text-gray-500 dark:text-gray-500">
                                    {!! $format($row['stock'], $row['unit']) !!}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    @if($row['to_buy'] > 0)
                                        <span class="text-danger-600 dark:text-danger-400 font-black text-lg">
                                            {!! $format($row['to_buy'], $row['unit']) !!}
                                        </span>
                                    @else
                                        <span class="text-success-600 dark:text-success-500 font-bold">✅ Достатньо</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <style>
        @media print {
            .no-print, .fi-sidebar, .fi-topbar, .fi-header { display: none !important; }
            .fi-main-ctn { padding: 0 !important; margin: 0 !important; }
            table { border: 1px solid #000 !important; width: 100% !important; }
            th, td { border-bottom: 1px solid #000 !important; padding: 8px !important; color: black !important; }
            tr { background: white !important; }
        }
    </style>
</x-filament-panels::page>