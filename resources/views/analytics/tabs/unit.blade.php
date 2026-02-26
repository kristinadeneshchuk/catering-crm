<div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden relative mb-8">
    <div class="p-4 border-b border-white/5">
        <h3 class="text-white font-semibold">Юніт-Економіка (за обраний період)</h3>
        <p class="text-zinc-500 text-sm mt-1">Детальний розбір прибутковості та поведінки клієнтів за кожною калорійністю.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="fin-table w-full">
            <thead>
                <tr>
                    <th class="text-left" style="text-align: left !important;">Раціон (ккал)</th>
                    <th style="text-align: center !important;" title="Скільки пакетів було доставлено">Дні (шт)</th>
                    <th class="text-blue-400" title="Відсоток грошей, які приносить цей раціон">Частка продажів</th>
                    <th class="text-indigo-400" title="Скільки в середньому платить 1 клієнт за весь пакет">Середній чек (Пакет)</th>
                    <th class="text-indigo-400" title="На скільки днів зазвичай купують">Сер. тривалість</th>
                    <th>Сер. виручка / день</th>
                    <th>Сер. FoodCost / день</th>
                    <th>Сер. прибуток / день</th>
                    <th class="text-avocado-500">Маржинальність</th>
                </tr>
            </thead>
            <tbody>
                @foreach($unitEconomics as $cal => $data)
                <tr class="row-hover text-zinc-300">
                    <td class="text-left font-bold text-white" style="text-align: left !important;">{{ $cal }} ккал</td>
                    
                    <td style="text-align: center !important;">
                        <span class="bg-zinc-800 text-zinc-300 px-2 py-1 rounded text-xs font-bold">{{ $data['count'] }}</span>
                    </td>
                    
                    <td class="font-semibold text-blue-300">{{ round($data['revenue_share'], 1) }}%</td>
                    <td class="font-bold text-indigo-300">{{ number_format($data['avg_order_value'], 0, '.', ' ') }} ₴</td>
                    <td class="text-indigo-300">{{ round($data['avg_duration'], 1) }} дн</td>
                    
                    <td class="text-emerald-300">{{ number_format($data['avg_revenue'], 2, '.', ' ') }} ₴</td>
                    <td class="text-rose-400">{{ number_format($data['avg_cost'], 2, '.', ' ') }} ₴</td>
                    <td class="text-avocado-500 font-bold">{{ number_format($data['avg_profit'], 2, '.', ' ') }} ₴</td>
                    <td class="font-bold {{ $data['margin'] >= 60 ? 'text-emerald-400' : ($data['margin'] >= 40 ? 'text-yellow-400' : 'text-rose-400') }}">
                        {{ round($data['margin'], 1) }}%
                    </td>
                </tr>
                @endforeach
                @if(empty($unitEconomics))
                <tr>
                    <td colspan="9" class="text-center py-8 text-zinc-500">За обраний період немає даних про доставлені раціони.</td>
                </tr>
                @endif
            </tbody>
        </table>
    </div>
</div>