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