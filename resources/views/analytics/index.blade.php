<!DOCTYPE html>
<html lang="uk" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Фінансова Аналітика | Avocado Food</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                fontFamily: { sans: ['Inter', 'sans-serif'] },
                extend: {
                    colors: {
                        zinc: { 850: '#202024', 900: '#18181b', 950: '#09090b' },
                        avocado: { 500: '#f59e0b', 600: '#d97706' }
                    }
                }
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
        .fin-table th, .fin-table td { 
            padding: 14px 20px; 
            border-bottom: 1px solid rgba(255, 255, 255, 0.05); 
            text-align: right;
            font-variant-numeric: tabular-nums;
        }
        .fin-table th {
            text-transform: uppercase; font-size: 11px; font-weight: 600; letter-spacing: 0.05em;
            color: #71717a; background: #09090b; position: sticky; top: 0; z-index: 10;
        }
        
        /* Фіксована перша колонка (Зліва) */
        .fin-table td:first-child, .fin-table th:first-child { 
            position: sticky; left: 0; z-index: 20; text-align: left;
            background: #18181b; border-right: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 10px 0 15px -3px rgba(0, 0, 0, 0.3);
        }
        .fin-table th:first-child { background: #09090b; z-index: 30; }

        /* Фіксована остання колонка (Разом - Справа) */
        .fin-table td:last-child, .fin-table th:last-child {
            position: sticky; right: 0; z-index: 20;
            background: #18181b; border-left: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: -10px 0 15px -3px rgba(0, 0, 0, 0.3);
            font-weight: 700; color: #fff;
        }
        .fin-table th:last-child { background: #09090b; z-index: 30; color: #f59e0b; }

        .row-hover:hover td { background-color: rgba(255, 255, 255, 0.02); }
        .row-hover:hover td:first-child, .row-hover:hover td:last-child { background-color: #202024; }
        
        /* Підпункти (з правильним прилипанням) */
        .sub-row td { padding-top: 8px; padding-bottom: 8px; font-size: 13px; color: #71717a; }
        .sub-row td:first-child { padding-left: 40px; }
        
        .sub-row td:first-child::before {
            content: ''; position: absolute; left: 24px; top: -14px; bottom: 50%; width: 2px;
            background: #3f3f46; border-bottom-left-radius: 4px;
        }
        .sub-row td:first-child::after {
            content: ''; position: absolute; left: 24px; top: 50%; width: 8px; height: 2px; background: #3f3f46;
        }

        .inline-input {
            background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff; border-radius: 6px; padding: 4px 8px; width: 70px;
            text-align: center; font-size: 13px; margin: 0 8px; transition: all 0.2s ease;
        }
        .inline-input:focus { outline: none; border-color: #f59e0b; background: rgba(245, 158, 11, 0.1); box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2); }
    </style>
</head>
<body class="p-4 md:p-8 antialiased selection:bg-avocado-500 selection:text-white">

    <div class="max-w-[1800px] mx-auto">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-8">
            <div>
                <h1 class="text-3xl font-bold text-white tracking-tight">Фінансова Аналітика</h1>
                <p class="text-zinc-400 mt-1 text-sm">Звіт про витрати та доходи закритих змін</p>
            </div>
            
            <button class="group relative flex items-center gap-2 bg-zinc-900 hover:bg-zinc-800 border border-zinc-700 text-white font-medium py-2 px-5 rounded-lg text-sm transition-all shadow-sm hover:border-zinc-500 overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-r from-emerald-500/0 via-emerald-500/10 to-emerald-500/0 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Експорт у Excel
            </button>
        </div>

        <form method="GET" action="{{ route('analytics.index') }}" class="bg-zinc-900/50 backdrop-blur-xl border border-white/10 p-2 rounded-2xl mb-8 flex flex-wrap items-center gap-2 shadow-xl">
            <div class="flex-grow min-w-[250px] p-2">
                <select class="w-full bg-transparent text-white text-sm font-medium focus:outline-none appearance-none cursor-pointer">
                    <option class="bg-zinc-900">Звіт про витрати та доходи (таблиця)</option>
                </select>
            </div>
            
            <div class="h-8 w-px bg-white/10 hidden md:block"></div>
            
            <div class="flex items-center gap-2 p-2">
                <input type="date" name="start_date" value="{{ $startDate }}" class="bg-transparent text-zinc-300 text-sm focus:outline-none focus:text-white cursor-pointer [&::-webkit-calendar-picker-indicator]:filter [&::-webkit-calendar-picker-indicator]:invert opacity-80 hover:opacity-100">
                <span class="text-zinc-600">→</span>
                <input type="date" name="end_date" value="{{ $endDate }}" class="bg-transparent text-zinc-300 text-sm focus:outline-none focus:text-white cursor-pointer [&::-webkit-calendar-picker-indicator]:filter [&::-webkit-calendar-picker-indicator]:invert opacity-80 hover:opacity-100">
            </div>

            <button type="submit" class="bg-avocado-500 hover:bg-avocado-600 text-white font-medium py-2 px-6 rounded-xl transition-all shadow-[0_0_15px_rgba(245,158,11,0.3)] hover:shadow-[0_0_20px_rgba(245,158,11,0.5)]">
                Розрахувати
            </button>
        </form>

        <div class="bg-zinc-900 border border-white/5 rounded-2xl shadow-2xl overflow-hidden relative">
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
        
    </div>

</body>
</html>