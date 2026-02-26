<div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden relative mb-8">
    <div class="p-4 border-b border-white/5">
        <h3 class="text-white font-semibold">Деталізація по днях (P&L)</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="fin-table">
            <thead>
                <tr>
                    <th class="w-80 min-w-[340px]">Показник</th>
                    @foreach($dates as $ymd => $dm)
                        <th>{{ $dm }}</th>
                    @endforeach
                    <th>Разом</th>
                </tr>
            </thead>
            <tbody>
                
                <tr class="row-hover text-zinc-300">
                    <td class="font-medium">Кількість доставлених раціонів</td>
                    @foreach($dates as $ymd => $dm) 
                        <td>{{ $rationsCount[$ymd] ?? 0 }}</td> 
                    @endforeach
                    <td class="text-white">{{ $totalRations ?? 0 }}</td>
                </tr>
                <tr class="row-hover text-white bg-white/[0.02]">
                    <td class="font-semibold text-sm">Вартість раціонів (Виручка)</td>
                    @foreach($dates as $ymd => $dm) 
                        <td class="font-medium">{{ number_format($revenueCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td> 
                    @endforeach
                    <td>{{ number_format($totalRevenue ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-rose-400 bg-rose-500/[0.03]">
                    <td class="font-semibold flex items-center gap-2">
                        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg>
                        Основні витрати
                    </td>
                    @foreach($dates as $ymd => $dm) <td class="font-medium">0 ₴</td> @endforeach
                    <td class="text-rose-400">0 ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>Собівартість продуктів</td>
                    @foreach($dates as $ymd => $dm) 
                        <td>{{ number_format($foodCostCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td> 
                    @endforeach
                    <td>{{ number_format($totalFoodCost ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>
                
                <tr class="row-hover sub-row">
                    <td>Витрати на доставку</td>
                    @foreach($dates as $ymd => $dm) <td>0 ₴</td> @endforeach
                    <td>0 ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>Витрати на пакування</td>
                    @foreach($dates as $ymd => $dm) <td>0 ₴</td> @endforeach
                    <td>0 ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>Фонд оплати праці (ФОП)</td>
                    @foreach($dates as $ymd => $dm) <td>0 ₴</td> @endforeach
                    <td>0 ₴</td>
                </tr>

                <tr class="row-hover text-emerald-400 bg-emerald-500/[0.05]">
                    <td class="font-bold text-[15px] flex items-center gap-2">
                        <svg class="w-5 h-5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08-.402-2.599-1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Операційний прибуток
                    </td>
                    @foreach($dates as $ymd => $dm) <td class="font-bold text-[15px]">0 ₴</td> @endforeach
                    <td class="text-emerald-400">0 ₴</td>
                </tr>

                <tr class="row-hover text-zinc-300">
                    <td class="font-medium">Середній чек</td>
                    @foreach($dates as $ymd => $dm) <td>0 ₴</td> @endforeach
                    <td>0 ₴</td>
                </tr>
                <tr class="row-hover text-zinc-300">
                    <td class="font-medium flex items-center">
                        Відсоток браку <input type="number" value="7" class="inline-input"> %
                    </td>
                    @foreach($dates as $ymd => $dm) <td class="text-zinc-500">0 ₴</td> @endforeach
                    <td class="text-zinc-500">0 ₴</td>
                </tr>
                <tr class="row-hover text-zinc-300">
                    <td class="font-medium flex items-center">
                        Інші витрати <input type="number" value="1000" class="inline-input"> грн/день
                    </td>
                    @foreach($dates as $ymd => $dm) <td class="text-zinc-500">1 000 ₴</td> @endforeach
                    <td class="text-zinc-500">0 ₴</td>
                </tr>

                <tr class="row-hover text-white bg-gradient-to-r from-emerald-600/20 to-transparent">
                    <td class="font-bold text-base text-emerald-300">Чистий прибуток</td>
                    @foreach($dates as $ymd => $dm) <td class="font-bold text-base text-emerald-300">0 ₴</td> @endforeach
                    <td class="text-emerald-400">0 ₴</td>
                </tr>
                <tr class="row-hover text-avocado-500">
                    <td class="font-semibold">Маржинальність</td>
                    @foreach($dates as $ymd => $dm) <td class="font-semibold">0 %</td> @endforeach
                    <td>0 %</td>
                </tr>

            </tbody>
        </table>
    </div>
</div>