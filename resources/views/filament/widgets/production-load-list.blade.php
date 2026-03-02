<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Навантаження на кухню (найближчі 10 днів)
        </x-slot>

        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">Дата</th>
                        <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">День тижня</th>
                        <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400 text-right">Кількість</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($loadList as $row)
                        {{-- 🔥 ПРИБРАВ HOVER ЕФЕКТИ З ЦЬОГО РЯДКА 🔥 --}}
                        <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                            <td class="py-3 px-4 text-sm font-medium text-gray-900 dark:text-gray-200">
                                {{ $row['date'] }}
                                @if($loop->first) 
                                    <span class="ml-2 text-[10px] uppercase font-bold text-emerald-500">(Сьогодні)</span>
                                @endif
                                @if($loop->iteration === 2) 
                                    <span class="ml-2 text-[10px] uppercase font-bold text-blue-500">(Завтра)</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-sm text-gray-500 dark:text-gray-400">
                                {{ $row['day_name'] }}
                            </td>
                            <td class="py-3 px-4 text-right">
                                <span class="inline-flex items-center justify-center min-w-[3rem] px-2.5 py-0.5 rounded-full text-xs font-bold {{ $row['total'] > 0 ? 'bg-amber-500 text-white shadow-sm' : 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400' }}">
                                    {{ $row['total'] }} шт
                                </span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>