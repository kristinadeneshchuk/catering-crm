<div class="grid grid-cols-1 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        <div class="absolute top-0 right-0 w-24 h-24 bg-avocado-500/10 rounded-full blur-2xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Виручка</p>
        <h3 class="text-2xl font-black text-white">{{ number_format($totalRevenue ?? 0, 0, '.', ' ') }} ₴</h3>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Брудний прибуток</p>
        <h3 class="text-2xl font-black text-emerald-400">{{ number_format(($totalRevenue ?? 0) - ($totalFoodCost ?? 0), 0, '.', ' ') }} ₴</h3>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Раціонів (дні)</p>
        <h3 class="text-2xl font-black text-white">{{ $totalRations ?? 0 }} <span class="text-sm text-zinc-500 font-medium">шт</span></h3>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        @php $avgFoodCostPercent = $totalRevenue > 0 ? ($totalFoodCost / $totalRevenue) * 100 : 0; @endphp
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Food Cost</p>
        <h3 class="text-2xl font-black {{ $avgFoodCostPercent > 35 ? 'text-rose-400' : 'text-emerald-400' }}">
            {{ round($avgFoodCostPercent, 1) }}%
        </h3>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        @php $avgCheck = $totalRations > 0 ? $totalRevenue / $totalRations : 0; @endphp
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Сер. дохід / раціон</p>
        <h3 class="text-2xl font-black text-white">{{ number_format($avgCheck, 0, '.', ' ') }} ₴</h3>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Всього страв (~)</p>
        <h3 class="text-2xl font-black text-white">{{ round($totalRations * 4.5) }} <span class="text-sm text-zinc-500 font-medium">шт</span></h3>
    </div>

    <div class="bg-zinc-900 border border-amber-500/20 p-5 rounded-2xl shadow-lg relative overflow-hidden">
        <div class="absolute top-0 right-0 w-24 h-24 bg-amber-500/10 rounded-full blur-2xl -mr-10 -mt-10"></div>
        <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Знижки</p>
        <h3 class="text-2xl font-black text-amber-400">{{ number_format($totalDiscount ?? 0, 0, '.', ' ') }} ₴</h3>
        @if(($totalRevenue ?? 0) + ($totalDiscount ?? 0) > 0)
            @php $discountPercent = (($totalRevenue + $totalDiscount) > 0) ? ($totalDiscount / ($totalRevenue + $totalDiscount)) * 100 : 0; @endphp
            <p class="text-zinc-500 text-xs mt-1">{{ round($discountPercent, 1) }}% від брутто</p>
        @endif
    </div>
</div>

<div class="rounded-2xl border border-amber-500/20 bg-amber-500/5 p-4 mb-6">
    {{-- Заголовок --}}
    <div class="flex items-center gap-2 mb-4">
        <svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
        <span class="text-amber-400 text-xs font-bold uppercase tracking-widest">Касовий розрив</span>
    </div>

    {{-- Група 1: За обраний період --}}
    <p class="text-zinc-600 text-[10px] font-bold uppercase tracking-widest mb-2 flex items-center gap-1.5">
        <span class="inline-block w-3 h-px bg-zinc-600"></span>
        За обраний період
        <span class="inline-block flex-1 h-px bg-zinc-800"></span>
    </p>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
        <div class="bg-zinc-900/60 border border-white/5 p-4 rounded-xl">
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Виручка (раціони)</p>
            <h3 class="text-2xl font-black text-emerald-400">{{ number_format($totalRevenue ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">По доставлених раціонах за період</p>
        </div>
        <div class="bg-zinc-900/60 border border-white/5 p-4 rounded-xl">
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Отримано коштів</p>
            <h3 class="text-2xl font-black text-blue-400">{{ number_format($cashReceivedPeriod ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">Реальні оплати від клієнтів за період</p>

            @if(!empty($cashReceivedByAccount) && $cashReceivedByAccount->isNotEmpty())
                <div class="mt-3 pt-3 border-t border-white/5 space-y-1.5">
                    @foreach($cashReceivedByAccount as $row)
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-zinc-400">{{ $row['name'] }}</span>
                            <span class="font-semibold text-blue-300">{{ number_format($row['total'], 0, '.', ' ') }} ₴</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Група 2: Поточний стан --}}
    <p class="text-zinc-600 text-[10px] font-bold uppercase tracking-widest mb-2 flex items-center gap-1.5">
        <span class="inline-block w-3 h-px bg-zinc-600"></span>
        Поточний стан (зараз)
        <span class="inline-block flex-1 h-px bg-zinc-800"></span>
    </p>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3">
        <div class="bg-zinc-900/60 border border-white/5 p-4 rounded-xl">
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Залишок на рахунках</p>
            <h3 class="text-2xl font-black text-violet-400">{{ number_format($cashBalance ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">Сума по всіх рахунках</p>
        </div>

        <div class="bg-zinc-900/60 border border-white/5 p-4 rounded-xl">
            @php $gapRisk = ($cashBalance ?? 0) < ($prepaidValue ?? 0); @endphp
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Передоплачені раціони</p>
            <h3 class="text-2xl font-black {{ $gapRisk ? 'text-rose-400' : 'text-orange-400' }}">{{ number_format($prepaidValue ?? 0, 0, '.', ' ') }} ₴</h3>
            <p class="text-zinc-600 text-xs mt-1">
                Оплачено, ще не доставлено
                @if($gapRisk)
                    &nbsp;<span class="text-rose-400 font-semibold">— ризик розриву!</span>
                @endif
            </p>
        </div>

        @php $freeCash = ($cashBalance ?? 0) - ($prepaidValue ?? 0); @endphp
        <div class="bg-zinc-900/60 border {{ $freeCash >= 0 ? 'border-emerald-500/30' : 'border-rose-500/30' }} p-4 rounded-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-20 h-20 {{ $freeCash >= 0 ? 'bg-emerald-500/10' : 'bg-rose-500/10' }} rounded-full blur-2xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Вільні кошти</p>
            <h3 class="text-2xl font-black {{ $freeCash >= 0 ? 'text-emerald-400' : 'text-rose-400' }}">
                {{ $freeCash >= 0 ? '' : '−' }}{{ number_format(abs($freeCash), 0, '.', ' ') }} ₴
            </h3>
            <p class="text-zinc-600 text-xs mt-1">
                @if($freeCash >= 0)
                    Гроші що вже ваші, без урахування авансів
                @else
                    <span class="text-rose-400 font-semibold">Авансів більше ніж є на рахунках — дефіцит!</span>
                @endif
            </p>
        </div>

        <div class="bg-zinc-900/60 border {{ ($totalClientDebt ?? 0) > 0 ? 'border-rose-500/30' : 'border-white/5' }} p-4 rounded-xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-20 h-20 {{ ($totalClientDebt ?? 0) > 0 ? 'bg-rose-500/10' : 'bg-zinc-500/5' }} rounded-full blur-2xl -mr-8 -mt-8"></div>
            <p class="text-zinc-500 text-[11px] font-bold uppercase tracking-widest mb-1">Борг клієнтів</p>
            <h3 class="text-2xl font-black {{ ($totalClientDebt ?? 0) > 0 ? 'text-rose-400' : 'text-zinc-400' }}">
                {{ number_format($totalClientDebt ?? 0, 0, '.', ' ') }} ₴
            </h3>
            <p class="text-zinc-600 text-xs mt-1">
                @if(($totalClientDebt ?? 0) > 0)
                    <span class="text-rose-400">{{ $debtorClientsCount ?? 0 }} {{ $debtorClientsCount === 1 ? 'клієнт' : ($debtorClientsCount < 5 ? 'клієнти' : 'клієнтів') }} з'їли, не заплатили</span>
                @else
                    Всі клієнти без боргу
                @endif
            </p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
    <div class="lg:col-span-2 bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg">
        <h3 class="text-white font-semibold mb-1">Динаміка: Виручка vs Собівартість</h3>
        <p class="text-zinc-500 text-xs mb-4">Показники по днях за обраний період</p>
        <div id="revenueChart" class="w-full h-[300px]"></div>
    </div>

    <div class="bg-zinc-900 border border-white/5 p-5 rounded-2xl shadow-lg">
        <h3 class="text-white font-semibold mb-1">Sales Mix (Популярність)</h3>
        <p class="text-zinc-500 text-xs mb-4">Розподіл доставлених раціонів</p>
        <div id="caloriesChart" class="w-full h-[300px] flex justify-center items-center"></div>
    </div>
</div>