<!DOCTYPE html>
<html lang="uk" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управлінська Аналітика | Avocado Food</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    
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
        .fin-table td:last-child, .fin-table th:last-child { position: sticky; right: 0; z-index: 20; background: #18181b; border-left: 1px solid rgba(255, 255, 255, 0.05); box-shadow: -10px 0 15px -3px rgba(0, 0, 0, 0.3); font-weight: 700; color: #fff; }
        .tab-btn { cursor: pointer; transition: all 0.2s ease; }
        .tab-active { border-bottom: 2px solid #f59e0b; color: #f59e0b; }
        .tab-inactive { color: #71717a; border-bottom: 2px solid transparent; }
        .hidden { display: none !important; }
        .inline-input { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); color: #fff; border-radius: 6px; padding: 4px 8px; width: 70px; text-align: center; font-size: 13px; margin: 0 8px; }
        /* Стилізація тултіпів для візуалу */
        .apexcharts-tooltip { background: #18181b !important; border: 1px solid rgba(255,255,255,0.1) !important; color: #fff !important; }
        .apexcharts-tooltip-title { background: #09090b !important; border-bottom: 1px solid rgba(255,255,255,0.05) !important; }
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
            <button onclick="switchTab('dashboard')" id="btn-dashboard" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Головний Дашборд</button>
            <button onclick="switchTab('unit')" id="btn-unit" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Юніт-Економіка</button>
            <button onclick="switchTab('marketing')" id="btn-marketing" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Маркетинг та Джерела</button>
            <button onclick="switchTab('retention')" id="btn-retention" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Утримання (Retention)</button>
            <button onclick="switchTab('finance')" id="btn-finance" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Фінанси (P&L)</button>
            <button onclick="switchTab('projects')" id="btn-projects" class="tab-btn pb-3 font-bold text-[13px] uppercase tracking-wider tab-inactive">Проєкти</button>
        </div>

        <form id="analytics-form" method="GET" action="{{ route('analytics.index') }}" class="bg-zinc-900/50 backdrop-blur-xl border border-white/10 p-2 rounded-2xl mb-8 flex flex-wrap items-center gap-2 shadow-xl">
            <input type="hidden" name="tab" id="active_tab_input" value="{{ $activeTab ?? 'dashboard' }}">
            
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

        <div id="content-dashboard" class="tab-content hidden">@include('analytics.tabs.dashboard')</div>
        <div id="content-unit" class="tab-content hidden">@include('analytics.tabs.unit')</div>
        <div id="content-marketing" class="tab-content hidden">@include('analytics.tabs.marketing')</div>
        <div id="content-retention" class="tab-content hidden">@include('analytics.tabs.retention')</div>
        <div id="content-finance" class="tab-content hidden">@include('analytics.tabs.finance')</div>
        <div id="content-projects" class="tab-content hidden">@include('analytics.tabs.projects')</div>
    </div>

<script>
    // --- 1. ЛОГІКА ТАБІВ ---
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(el => {
            el.classList.remove('tab-active');
            el.classList.add('tab-inactive');
        });

        const targetContent = document.getElementById('content-' + tabId);
        const targetBtn = document.getElementById('btn-' + tabId);

        if (targetContent && targetBtn) {
            targetContent.classList.remove('hidden');
            targetBtn.classList.remove('tab-inactive');
            targetBtn.classList.add('tab-active');
            
            history.pushState(null, null, '#' + tabId);
            
            const tabInput = document.getElementById('active_tab_input');
            if (tabInput) tabInput.value = tabId;
        }
        window.dispatchEvent(new Event('resize'));
    }

    document.addEventListener('DOMContentLoaded', function() {
        const phpTab = "{{ $activeTab ?? 'dashboard' }}";
        const hashTab = window.location.hash.replace('#', '');
        switchTab(phpTab || hashTab || 'dashboard');

        const mainForm = document.getElementById('analytics-form');
        
        // Автоматичне оновлення при зміні цифр браку/витрат
        document.addEventListener('change', function(e) {
            if (e.target.name === 'spoilage_percent' || e.target.name === 'other_expenses') {
                if (mainForm) mainForm.submit();
            }
        });
    });

    // --- 2. ПІДГОТОВКА ДАНИХ (БЕЗПЕЧНА) ---
    const chartDates = {!! json_encode(array_values($dates ?? [])) !!};
    const chartRevenue = {!! json_encode(array_values($revenueCount ?? [])) !!};
    const chartFoodCost = {!! json_encode(array_values($foodCostCount ?? [])) !!};
    const chartDiscount = {!! json_encode(array_values($discountCount ?? [])) !!};
    
    @php
        $pieLabels = []; $pieSeries = [];
        if(!empty($unitEconomics)) {
            foreach($unitEconomics as $cal => $data) {
                $pieLabels[] = $cal . ' ккал';
                $pieSeries[] = (int)$data['count'];
            }
        }
    @endphp
    
    const pieLabels = {!! json_encode($pieLabels) !!};
    const pieSeries = {!! json_encode($pieSeries) !!};

    // --- 3. ГРАФІК ДИНАМІКИ (ВІДНОВЛЕНИЙ КРАСИВИЙ ВІЗУАЛ) ---
    if (document.querySelector("#revenueChart")) {
        new ApexCharts(document.querySelector("#revenueChart"), {
            series: [
                { name: 'Виручка (нетто)', data: chartRevenue },
                { name: 'Собівартість', data: chartFoodCost },
                { name: 'Знижки', data: chartDiscount }
            ],
            chart: { 
                type: 'area', 
                height: 300, 
                toolbar: { show: false }, 
                background: 'transparent',
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#34d399', '#f43f5e', '#f59e0b'],
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] }
            },
            stroke: { curve: 'smooth', width: 2 },
            xaxis: { 
                categories: chartDates,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: { style: { colors: '#71717a' } }
            },
            yaxis: {
                labels: { 
                    style: { colors: '#71717a' },
                    formatter: function (val) { return val.toLocaleString() + " ₴"; }
                }
            },
            grid: { borderColor: 'rgba(255,255,255,0.05)', strokeDashArray: 4 },
            dataLabels: { enabled: false },
            theme: { mode: 'dark' }
        }).render();
    }

    // --- 4. ГРАФІК ПОПУЛЯРНОСТІ (ВІДНОВЛЕНИЙ КРАСИВИЙ ВІЗУАЛ) ---
    if (document.querySelector("#caloriesChart")) {
        new ApexCharts(document.querySelector("#caloriesChart"), {
            series: pieSeries.length > 0 ? pieSeries : [1],
            labels: pieLabels.length > 0 ? pieLabels : ['Немає даних'],
            chart: { 
                type: 'donut', 
                height: 300, 
                background: 'transparent',
                fontFamily: 'Inter, sans-serif'
            },
            colors: ['#f59e0b', '#3b82f6', '#10b981', '#ec4899', '#8b5cf6', '#06b6d4', '#f43f5e', '#64748b'],
            plotOptions: {
                pie: {
                    donut: {
                        size: '75%',
                        labels: {
                            show: true,
                            name: { show: true, color: '#a1a1aa', fontSize: '12px' },
                            value: { show: true, color: '#fff', fontSize: '24px', fontWeight: '900',
                                formatter: function(val) { return val; }
                            },
                            total: { show: true, label: 'Всього', color: '#71717a',
                                formatter: function (w) {
                                    return w.globals.seriesNumbers.reduce((a, b) => a + b, 0);
                                }
                            }
                        }
                    }
                }
            },
            stroke: { show: true, colors: '#18181b', width: 3 },
            dataLabels: { enabled: false },
            legend: { position: 'bottom', labels: { colors: '#71717a' } },
            theme: { mode: 'dark' }
        }).render();
    }
</script>
</body>
</html>