<div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden mb-8">
    <div class="p-4 border-b border-white/5">
        <h3 class="text-white font-semibold">Ефективність каналів продажу</h3>
        <p class="text-zinc-500 text-sm mt-1">Аналіз того, звідки приходять найприбутковіші клієнти.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="fin-table w-full">
            <thead>
                <tr>
                    <th class="text-left" style="text-align: left !important;">Джерело (Source)</th>
                    <th style="text-align: center !important;">К-сть клієнтів</th>
                    <th>Загальна виручка</th>
                    <th>Частка в доході (%)</th>
                    <th class="text-avocado-500">Дохід на 1 клієнта (LTV proxy)</th>
                </tr>
            </thead>
            <tbody>
                @foreach($marketingStats as $source => $data)
                <tr class="row-hover text-zinc-300">
                    <td class="text-left font-bold text-white" style="text-align: left !important;">{{ $source }}</td>
                    <td style="text-align: center !important;">
                        <span class="bg-zinc-800 text-zinc-300 px-2 py-1 rounded text-xs font-bold">{{ $data['unique_clients'] }}</span>
                    </td>
                    <td class="text-white font-medium">{{ number_format($data['revenue'], 0, '.', ' ') }} ₴</td>
                    <td>
                        <div class="flex items-center justify-end gap-2">
                            <div class="w-16 bg-zinc-800 h-1.5 rounded-full overflow-hidden">
                                <div class="bg-blue-500 h-full" style="width: {{ $data['revenue_share'] }}%"></div>
                            </div>
                            <span>{{ round($data['revenue_share'], 1) }}%</span>
                        </div>
                    </td>
                    <td class="text-emerald-400 font-bold">{{ number_format($data['avg_lva'], 0, '.', ' ') }} ₴</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>