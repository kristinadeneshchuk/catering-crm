<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            Замовлення очікуючі оплати
        </x-slot>
        <x-slot name="headerEnd">
            <span class="text-sm font-semibold text-danger-500">
                {{ $debtsList->count() }} замовлень
            </span>
        </x-slot>

        @if($debtsList->isEmpty())
            <div class="py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                Усі замовлення оплачені ✓
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">№</th>
                            <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">Клієнт</th>
                            <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">Дата</th>
                            <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400">Статус</th>
                            <th class="pb-2 pt-0 px-4 font-semibold text-sm text-gray-500 dark:text-gray-400 text-right">К оплаті</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($debtsList as $row)
                            <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                                <td class="py-3 px-4 text-sm text-gray-400 dark:text-gray-500">
                                    {{ $row['order_id'] }}
                                </td>
                                <td class="py-3 px-4 text-sm font-medium">
                                    @if($row['client_url'])
                                        <a href="{{ $row['client_url'] }}"
                                           class="text-primary-600 dark:text-primary-400 hover:underline">
                                            {{ $row['client_name'] }}
                                        </a>
                                    @else
                                        <span class="text-gray-700 dark:text-gray-200">{{ $row['client_name'] }}</span>
                                    @endif
                                </td>
                                <td class="py-3 px-4 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $row['start_date'] }}–{{ $row['end_date'] }}
                                    <span class="ml-1 text-xs text-gray-400">({{ $row['duration'] }} дн.)</span>
                                </td>
                                <td class="py-3 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold
                                        {{ $row['status'] === 'active'
                                            ? 'bg-success-100 text-success-700 dark:bg-success-950 dark:text-success-400'
                                            : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                        {{ $row['status'] === 'active' ? 'Активний' : 'Новий' }}
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-right">
                                    <span class="text-sm font-bold text-danger-600 dark:text-danger-400">
                                        {{ number_format($row['due'], 0, '.', ' ') }} ₴
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-gray-200 dark:border-gray-700">
                            <td colspan="4" class="pt-3 px-4 text-sm font-semibold text-gray-500 dark:text-gray-400">
                                Всього боргів
                            </td>
                            <td class="pt-3 px-4 text-right">
                                <span class="text-base font-bold text-danger-600 dark:text-danger-400">
                                    {{ number_format($debtsList->sum('due'), 0, '.', ' ') }} ₴
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
