<!DOCTYPE html>
<html lang="uk" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управлінська Аналітика | Avocado Food</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                extend: { colors: { zinc: { 850: '#202024', 900: '#18181b', 950: '#09090b' }, avocado: { 500: '#f59e0b', 600: '#d97706' } } }
            }
        }
    </script>
    <style>
        body { background-color: #09090b; color: #a1a1aa; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #3f3f46; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #52525b; }
        .fin-table { width: 100%; border-collapse: separate; border-spacing: 0; white-space: nowrap; }
        .fin-table th, .fin-table td { padding: 14px 20px; border-bottom: 1px solid rgba(255, 255, 255, 0.05); text-align: right; font-variant-numeric: tabular-nums; }
        .fin-table th { text-transform: uppercase; font-size: 11px; font-weight: 600; letter-spacing: 0.05em; color: #71717a; background: #09090b; position: sticky; top: 0; z-index: 10; }
        .fin-table td:first-child, .fin-table th:first-child { position: sticky; left: 0; z-index: 20; text-align: left; background: #18181b; border-right: 1px solid rgba(255, 255, 255, 0.05); box-shadow: 10px 0 15px -3px rgba(0, 0, 0, 0.3); }
        .fin-table th:first-child { background: #09090b; z-index: 30; }
        .fin-table td:last-child, .fin-table th:last-child { position: sticky; right: 0; z-index: 20; background: #18181b; border-left: 1px solid rgba(255, 255, 255, 0.05); box-shadow: -10px 0 15px -3px rgba(0, 0, 0, 0.3); font-weight: 700; color: #fff; }
        .fin-table th:last-child { background: #09090b; z-index: 30; color: #f59e0b; }
        .row-hover:hover td { background-color: rgba(255, 255, 255, 0.02); }
        .row-hover:hover td:first-child, .row-hover:hover td:last-child { background-color: #202024; }
        .sub-row td { padding-top: 8px; padding-bottom: 8px; font-size: 13px; color: #71717a; }
        .sub-row td:first-child { padding-left: 40px; }
        .sub-row td:first-child::before { content: ''; position: absolute; left: 24px; top: -14px; bottom: 50%; width: 2px; background: #3f3f46; border-bottom-left-radius: 4px; }
        .sub-row td:first-child::after { content: ''; position: absolute; left: 24px; top: 50%; width: 8px; height: 2px; background: #3f3f46; }
        .tab-btn { cursor: pointer; transition: all 0.2s ease; }
        .tab-active { border-bottom: 2px solid #f59e0b; color: #f59e0b; }
        .tab-inactive { color: #71717a; border-bottom: 2px solid transparent; }
        .tab-inactive:hover { color: #fff; border-bottom: 2px solid #52525b; }
        .hidden { display: none !important; }
        .apexcharts-tooltip { background: #18181b !important; border: 1px solid rgba(255,255,255,0.1) !important; box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5) !important; }
        .apexcharts-tooltip-title { background: #09090b !important; border-bottom: 1px solid rgba(255,255,255,0.05) !important; font-family: 'Inter', sans-serif !important; font-weight: bold !important; }
        .apexcharts-text { fill: #a1a1aa !important; font-family: 'Inter', sans-serif !important; }
        .apexcharts-legend-text { color: #a1a1aa !important; }
        .inline-input { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: #fff; border-radius: 6px; padding: 4px 8px; width: 70px; text-align: center; font-size: 13px; margin: 0 8px; transition: all 0.2s ease; }
        .inline-input:focus { outline: none; border-color: #f59e0b; background: rgba(245, 158, 11, 0.1); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2); }
    </style>
</head>
<body class="p-4 md:p-8 antialiased selection:bg-avocado-500 selection:text-white">

    <div class="max-w-[1800px] mx-auto">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white tracking-tight">Управлінська Аналітика</h1>
                <p class="text-zinc-400 mt-1 text-sm">Аналітика, фінанси та метрики росту</p>
            </div>
            <button class="group relative flex items-center gap-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-white font-medium py-2 px-5 rounded-lg text-sm transition-all shadow-sm hover:border-zinc-500 overflow-hidden">
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Експорт
            </button>
        </div>

        <div class="flex flex-wrap items-center gap-6 mb-8 border-b border-white/10">
            <button onclick="switchTab('dashboard')" id="btn-dashboard" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-active">Головний Дашборд</button>
            <button onclick="switchTab('unit')" id="btn-unit" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Юніт-Економіка</button>
            <button onclick="switchTab('marketing')" id="btn-marketing" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Маркетинг та Джерела</button>
            <button onclick="switchTab('retention')" id="btn-retention" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Утримання (Retention)</button>
            <button onclick="switchTab('finance')" id="btn-finance" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Фінанси (P&L)</button>
        </div>

        <form method="GET" action="{{ route('analytics.index') }}" class="bg-zinc-900/50 backdrop-blur-xl border border-white/10 p-2 rounded-2xl mb-8 flex flex-wrap items-center gap-2 shadow-xl">
            <div class="flex items-center gap-2 p-2">
                <span class="text-zinc-500 text-sm font-medium mr-2">Період:</span>
                <input type="date" name="start_date" value="{{ $startDate }}" class="bg-transparent text-zinc-300 text-sm focus:outline-none focus:text-white cursor-pointer [&::-webkit-calendar-picker-indicator]:filter [&::-webkit-calendar-picker-indicator]:invert opacity-80 hover:opacity-100">
                <span class="text-zinc-600">→</span>
                <input type="date" name="end_date" value="{{ $endDate }}" class="bg-transparent text-zinc-300 text-sm focus:outline-none focus:text-white cursor-pointer [&::-webkit-calendar-picker-indicator]:filter [&::-webkit-calendar-picker-indicator]:invert opacity-80 hover:opacity-100">
            </div>
            <button type="submit" class="bg-avocado-500 hover:bg-avocado-600 text-white font-medium py-2 px-6 rounded-xl transition-all shadow-[0_0_15px_rgba(245,158,11,0.3)] hover:shadow-[0_0_20px_rgba(245,158,11,0.5)]">
                Оновити дані
            </button>
        </form>

        <div id="content-dashboard" class="tab-content block">@include('analytics.tabs.dashboard')</div>
        <div id="content-unit" class="tab-content hidden">@include('analytics.tabs.unit')</div>
        <div id="content-marketing" class="tab-content hidden">@include('analytics.tabs.marketing')</div>
        
        <div id="content-retention" class="tab-content hidden">@include('analytics.tabs.retention')</div>
        
        <div id="content-finance" class="tab-content hidden">@include('analytics.tabs.finance')</div>
    </div>

<script>
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.getElementById('content-' + tabId).classList.remove('hidden');

        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('tab-active');
            el.classList.add('tab-inactive');
        });
        document.getElementById('btn-' + tabId).classList.remove('tab-inactive');
        document.getElementById('btn-' + tabId).classList.add('tab-active');
        
        window.dispatchEvent(new Event('resize'));
    }

    const chartDates = {!! json_encode(array_values($dates)) !!};
    const chartRevenue = {!! json_encode(array_values($revenueCount)) !!};
    const chartFoodCost = {!! json_encode(array_values($foodCostCount)) !!};
    
    @php
        $pieLabels = []; $pieSeries = [];
        if(!empty($unitEconomics)) { 
            foreach($unitEconomics as $cal => $data) { 
                $pieLabels[] = $cal . ' ккал'; 
                $pieSeries[] = $data['count']; 
            } 
        }
    @endphp
    
    const pieLabels = {!! json_encode($pieLabels) !!};
    const pieSeries = {!! json_encode($pieSeries) !!};

    // 🔥 ВІДНОВЛЕНИЙ ГРАФІК ДИНАМІКИ (ГРАДІЄНТ + ПЛАВНІСТЬ)
    new ApexCharts(document.querySelector("#revenueChart"), {
        series: [{ name: 'Виручка', data: chartRevenue }, { name: 'Собівартість', data: chartFoodCost }],
        chart: { 
            type: 'area', 
            height: 300, 
            toolbar: { show: false }, 
            background: 'transparent', // Прозорий фон
            fontFamily: 'Inter, sans-serif' 
        },
        colors: ['#34d399', '#f43f5e'],
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] }
        },
        dataLabels: { enabled: false }, // Вимикаємо цифри на лініях
        stroke: { curve: 'smooth', width: 2 },
        xaxis: { 
            categories: chartDates, 
            axisBorder: { show: false },
            axisTicks: { show: false },
            labels: { style: { colors: '#71717a' } } 
        },
        yaxis: { 
            labels: { style: { colors: '#71717a' }, formatter: function (value) { return value + " ₴"; } } 
        },
        grid: { borderColor: 'rgba(255,255,255,0.05)', strokeDashArray: 4 }, 
        theme: { mode: 'dark' }
    }).render();

    // 🔥 ВІДНОВЛЕНИЙ ЧАРТ ПОПУЛЯРНОСТІ (БЕЗ СІРОЇ ПІДКЛАДКИ)
    new ApexCharts(document.querySelector("#caloriesChart"), {
        series: pieSeries.length > 0 ? pieSeries : [1],
        labels: pieLabels.length > 0 ? pieLabels : ['Немає даних'],
        chart: { 
            type: 'donut', 
            height: 300, 
            fontFamily: 'Inter, sans-serif',
            background: 'transparent' // 🔥 ОСЬ ТУТ МАЄ БУТИ ЦЕЙ ПАРАМЕТР
        },
        colors: ['#f59e0b', '#3b82f6', '#10b981', '#ec4899', '#8b5cf6', '#06b6d4', '#f43f5e', '#64748b'],
        plotOptions: { 
            pie: { 
                donut: { 
                    size: '70%', 
                    labels: { 
                        show: true, 
                        name: { color: '#a1a1aa' },
                        value: { color: '#fff', fontSize: '24px', fontWeight: 'bold' } 
                    } 
                } 
            } 
        },
        dataLabels: { enabled: false }, // Вимикаємо відсотки поверх чарта
        stroke: { show: true, colors: '#18181b', width: 2 }, 
        theme: { mode: 'dark' }, 
        legend: { position: 'bottom' }
    }).render();
</script>
</body>
</html>
