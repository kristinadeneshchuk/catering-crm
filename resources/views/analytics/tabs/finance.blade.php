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
                    <td class="font-semibold text-sm">Вартість раціонів (Виручка нетто)</td>
                    @foreach($dates as $ymd => $dm)
                        <td class="font-medium text-emerald-400">{{ number_format($revenueCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-emerald-400">{{ number_format($totalRevenue ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-amber-400 bg-amber-500/[0.03]">
                    <td class="font-medium text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
                        Знижки (відраховано)
                    </td>
                    @foreach($dates as $ymd => $dm)
                        <td class="text-amber-400/70 text-sm">
                            {{ ($discountCount[$ymd] ?? 0) > 0 ? '−' . number_format($discountCount[$ymd], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                    @endforeach
                    <td class="text-amber-400 font-medium">{{ $totalDiscount > 0 ? '−' . number_format($totalDiscount, 0, '.', ' ') . ' ₴' : '—' }}</td>
                </tr>

                <tr class="row-hover text-rose-400 bg-rose-500/[0.03]">
                    <td class="font-semibold flex items-center gap-2">
                        <svg class="w-4 h-4 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 17h8m0 0V9m0 8l-8-8-4 4-6-6"></path></svg>
                        Основні витрати
                    </td>
                    @foreach($dates as $ymd => $dm)
                        @php
                            $dailyBasicExp = ($foodCostCount[$ymd] ?? 0) + ($fopCount[$ymd] ?? 0) + ($packagingCount[$ymd] ?? 0);
                        @endphp
                        <td class="font-medium text-rose-300">{{ number_format($dailyBasicExp, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-rose-400">{{ number_format(($totalFoodCost + $totalFop + $totalPackagingCost), 0, '.', ' ') }} ₴</td>
                </tr>
                
                <tr class="row-hover sub-row">
                    <td>Собівартість продуктів</td>
                    @foreach($dates as $ymd => $dm) 
                        <td>{{ number_format($foodCostCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td> 
                    @endforeach
                    <td>{{ number_format($totalFoodCost ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>
                
                <tr class="row-hover sub-row">
                    <td>Компенсація кур'єрам (пальне + амортизація)</td>
                    @foreach($dates as $ymd => $dm)
                        <td>{{ isset($courierCompByDate[$ymd]) && $courierCompByDate[$ymd] > 0 ? $courierCompByDate[$ymd] . ' ₴' : '0 ₴' }}</td>
                    @endforeach
                    <td>{{ $totalCourierComp > 0 ? number_format($totalCourierComp, 0, '.', ' ') . ' ₴' : '0 ₴' }}</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>Витрати на пакування</td>
                    @foreach($dates as $ymd => $dm)
                        <td>{{ number_format($packagingCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td>{{ number_format($totalPackagingCost ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>ФОП — кухня</td>
                    @foreach($dates as $ymd => $dm)
                        <td>{{ number_format($fopKitchenCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td>{{ number_format($totalFopKitchen ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>ФОП — кур'єри</td>
                    @foreach($dates as $ymd => $dm)
                        <td>{{ number_format($fopCouriersCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td>{{ number_format($totalFopCouriers ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>
                <tr class="row-hover sub-row">
                    <td>ФОП — решта (менеджмент, маркетинг, інше)</td>
                    @foreach($dates as $ymd => $dm)
                        <td>{{ number_format($fopOtherCount[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td>{{ number_format($totalFopOther ?? 0, 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-emerald-400 bg-emerald-500/[0.05]">
                    <td class="font-bold text-[15px] flex items-center gap-2">
                        <svg class="w-5 h-5 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08-.402-2.599-1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Операційний прибуток
                    </td>
                    @foreach($dates as $ymd => $dm)
                        @php $opProfit = ($revenueCount[$ymd] ?? 0) - ($foodCostCount[$ymd] ?? 0) - ($fopCount[$ymd] ?? 0) - ($packagingCount[$ymd] ?? 0); @endphp
                        <td class="font-bold text-[15px] {{ $opProfit < 0 ? 'text-rose-500' : 'text-emerald-400' }}">
                            {{ number_format($opProfit, 0, '.', ' ') }} ₴
                        </td>
                    @endforeach
                    <td class="text-emerald-400">{{ number_format(($totalRevenue - $totalFoodCost - $totalFop - $totalPackagingCost), 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-zinc-300">
                    <td class="font-medium text-sm">Середній чек</td>
                    @foreach($dates as $ymd => $dm) 
                        @php $avg = ($rationsCount[$ymd] ?? 0) > 0 ? ($revenueCount[$ymd] / $rationsCount[$ymd]) : 0; @endphp
                        <td>{{ number_format($avg, 0, '.', ' ') }} ₴</td> 
                    @endforeach
                    <td>{{ number_format($totalRations > 0 ? $totalRevenue / $totalRations : 0, 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-zinc-300 bg-rose-500/[0.01]">
                    <td class="font-medium flex items-center gap-2">
                        Відсоток браку 
                        <input type="number" name="spoilage_percent" form="analytics-form" 
                               value="{{ $spoilagePercent }}" 
                               class="inline-input border-rose-500/30 focus:border-rose-500 w-16"> %
                    </td>
                    @foreach($dates as $ymd => $dm)
                        @php $spoilage = (($foodCostCount[$ymd] ?? 0) + ($packagingCount[$ymd] ?? 0)) * ($spoilagePercent / 100); @endphp
                        <td class="text-rose-400/70 text-sm italic">{{ number_format($spoilage, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-rose-400">{{ number_format(($totalFoodCost + $totalPackagingCost) * ($spoilagePercent / 100), 0, '.', ' ') }} ₴</td>
                </tr>

                <tr class="row-hover text-zinc-300">
                    <td class="font-medium flex items-center gap-2">
                        Інші витрати
                        <input type="number" name="other_expenses" form="analytics-form"
                               value="{{ $otherExpenses }}"
                               class="inline-input border-zinc-700 w-20"> грн/день
                    </td>
                    @foreach($dates as $ymd => $dm)
                        <td class="text-zinc-500 text-sm">{{ number_format($otherExpenses, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-zinc-500">{{ number_format(count($dates) * $otherExpenses, 0, '.', ' ') }} ₴</td>
                </tr>

                @if($monthlyRent > 0)
                <tr class="row-hover text-zinc-400">
                    <td class="font-medium text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                        Оренда
                    </td>
                    @foreach($dates as $ymd => $dm)
                        <td class="text-zinc-500 text-sm">{{ number_format($rentByDay[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-zinc-400">{{ number_format(array_sum($rentByDay), 0, '.', ' ') }} ₴</td>
                </tr>
                @endif

                @if($monthlyUtilities > 0)
                <tr class="row-hover text-zinc-400">
                    <td class="font-medium text-sm flex items-center gap-2">
                        <svg class="w-4 h-4 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                        Комунальні послуги
                    </td>
                    @foreach($dates as $ymd => $dm)
                        <td class="text-zinc-500 text-sm">{{ number_format($utilitiesByDay[$ymd] ?? 0, 0, '.', ' ') }} ₴</td>
                    @endforeach
                    <td class="text-zinc-400">{{ number_format(array_sum($utilitiesByDay), 0, '.', ' ') }} ₴</td>
                </tr>
                @endif

                <tr class="row-hover text-white bg-gradient-to-r from-emerald-600/20 to-transparent">
                    <td class="font-bold text-base text-emerald-300">Чистий прибуток</td>
                    @foreach($dates as $ymd => $dm)
                        @php
                            $dailySpoilage = (($foodCostCount[$ymd] ?? 0) + ($packagingCount[$ymd] ?? 0)) * ($spoilagePercent / 100);
                            $dailyNet = ($revenueCount[$ymd] ?? 0) - ($foodCostCount[$ymd] ?? 0) - ($fopCount[$ymd] ?? 0) - ($packagingCount[$ymd] ?? 0) - $dailySpoilage - $otherExpenses - ($courierCompByDate[$ymd] ?? 0) - ($rentByDay[$ymd] ?? 0) - ($utilitiesByDay[$ymd] ?? 0);
                        @endphp
                        <td class="font-bold text-base {{ $dailyNet < 0 ? 'text-rose-400' : 'text-emerald-300' }}">
                            {{ number_format($dailyNet, 0, '.', ' ') }} ₴
                        </td>
                    @endforeach
                    <td class="text-emerald-400">
                        @php
                            $totalSpoilage = ($totalFoodCost + $totalPackagingCost) * ($spoilagePercent / 100);
                            $totalOther    = count($dates) * $otherExpenses;
                            $totalRentAll  = array_sum($rentByDay);
                            $totalUtilAll  = array_sum($utilitiesByDay);
                            $totalNet      = $totalRevenue - $totalFoodCost - $totalFop - $totalPackagingCost - $totalSpoilage - $totalOther - ($totalCourierComp ?? 0) - $totalRentAll - $totalUtilAll;
                        @endphp
                        {{ number_format($totalNet, 0, '.', ' ') }} ₴
                    </td>
                </tr>
                <tr class="row-hover text-avocado-500">
                    <td class="font-semibold text-sm uppercase tracking-wider">Маржинальність</td>
                    @foreach($dates as $ymd => $dm)
                        @php
                            $dailySpoilage = (($foodCostCount[$ymd] ?? 0) + ($packagingCount[$ymd] ?? 0)) * ($spoilagePercent / 100);
                            $profit = ($revenueCount[$ymd] ?? 0) - ($foodCostCount[$ymd] ?? 0) - ($fopCount[$ymd] ?? 0) - ($packagingCount[$ymd] ?? 0) - $dailySpoilage - $otherExpenses - ($courierCompByDate[$ymd] ?? 0) - ($rentByDay[$ymd] ?? 0) - ($utilitiesByDay[$ymd] ?? 0);
                            $margin = ($revenueCount[$ymd] ?? 0) > 0 ? ($profit / $revenueCount[$ymd]) * 100 : 0;
                        @endphp
                        <td class="font-semibold {{ $margin < 20 ? 'text-rose-400' : 'text-avocado-500' }}">
                            {{ round($margin) }} %
                        </td>
                    @endforeach
                    @php
                        $totalMargin = $totalRevenue > 0 ? ($totalNet / $totalRevenue) * 100 : 0;
                    @endphp
                    <td class="font-bold">{{ round($totalMargin) }} %</td>
                </tr>

            </tbody>
        </table>
    </div>
</div>

{{-- 🔥 СКРИПТ АВТОМАТИЧНОГО ПЕРЕРАХУНКУ --}}
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const spoilageInput = document.querySelector('input[name="spoilage_percent"]');
        const expensesInput = document.querySelector('input[name="other_expenses"]');
        const mainForm = document.getElementById('analytics-form');

        if (mainForm) {
            const submitForm = () => mainForm.submit();

            // Спрацьовує, коли ти закінчила вводити або клацнула стрілочку
            if (spoilageInput) spoilageInput.addEventListener('change', submitForm);
            if (expensesInput) expensesInput.addEventListener('change', submitForm);

            // Також спрацьовує при натисканні Enter
            [spoilageInput, expensesInput].forEach(input => {
                if (input) {
                    input.addEventListener('keypress', function(e) {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            submitForm();
                        }
                    });
                }
            });
        }
    });
</script>