<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Фасувальний лист {{ $date }}</title>
    <style>
        /* ВАЖЛИВО: Ці 2 рядки змушують браузер друкувати кольоровий фон! */
        body { 
            font-family: DejaVu Sans, sans-serif; 
            font-size: 12px; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important;
        }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        
        /* 🔥 Робимо всі комірки таблиці ще сірішими 🔥 */
        th, td { 
            border: 1px solid #ccc; 
            padding: 4px; 
            text-align: center; 
            background-color: #e5e7eb; /* Помітний сірий фон */
            color: #111827; 
        }
        
        th.header-green { background-color: #4ade80 !important; color: white !important; font-size: 13px; }
        .text-left { text-align: left; }
        
        /* 🔥 КОЛЬОРИ КАЛОРІЙНОСТЕЙ (як на графіку) 🔥 */
        .kcal-950 { background-color: #a3e635 !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-1200 { background-color: #22c55e !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-1500 { background-color: #fde047 !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.6); } 
        .kcal-1800 { background-color: #fbbf24 !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-2100 { background-color: #f97316 !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-2500 { background-color: #ef4444 !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-3000 { background-color: #b91c1c !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 
        .kcal-3500 { background-color: #7f1d1d !important; color: white !important; font-size: 15px; font-weight: bold; text-shadow: 0px 1px 2px rgba(0,0,0,0.4); } 

        /* Стиль для комірок з кількістю порцій (робимо темнішими для контрасту) */
        .portions-title { background-color: #d1d5db !important; font-weight: bold; font-size: 12px; }
        .portions-cell { background-color: #94a3b8 !important; color: #eff6ff !important; font-size: 16px; font-weight: bold; text-shadow: 0px 1px 1px rgba(0,0,0,0.3); }

        /* 🔥 КОЛЬОРИ ПРИЙОМІВ ЇЖІ 🔥 */
        .meal-title { padding: 6px 10px; font-weight: bold; margin-top: 20px; font-size: 14px; text-transform: uppercase; border-radius: 4px 4px 0 0; }
        .meal-snidanok { background-color: #14b8a6 !important; color: white !important; text-shadow: 0px 1px 2px rgba(0,0,0,0.3); } 
        .meal-perekus-1 { background-color: #a3e635 !important; color: black !important; } 
        .meal-obid { background-color: #fb923c !important; color: white !important; text-shadow: 0px 1px 2px rgba(0,0,0,0.3); } 
        .meal-perekus-2 { background-color: #f472b6 !important; color: white !important; text-shadow: 0px 1px 2px rgba(0,0,0,0.3); } 
        .meal-vecherya { background-color: #38bdf8 !important; color: white !important; text-shadow: 0px 1px 2px rgba(0,0,0,0.3); } 
        .meal-default { background-color: #94a3b8 !important; color: white !important; } 

        /* Стиль для блоку замін */
        .notes-box {
            background-color: #fff7ed;
            border: 1px dashed #f97316;
            padding: 8px;
            margin-bottom: 20px;
            font-size: 11px;
            color: #c2410c;
        }
        .notes-header { font-weight: bold; margin-bottom: 4px; }
        .note-row { margin-bottom: 3px; }
        .note-id { font-weight: bold; color: #374151; }
        .note-name { font-weight: bold; }
        .note-badge {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            margin: 0 2px;
            background-color: #6b7280;
            color: white;
        }
        .note-badge-avocado { background-color: #16a34a; }
        .note-badge-ufit    { background-color: #7c3aed; }
        @media print {
            a[href*="packaging-assembly"] { display: none !important; }
        }
        .note-kcal {
            display: inline-block;
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 10px;
            font-weight: bold;
            background-color: #fbbf24;
            color: white;
            margin: 0 2px;
        }
    </style>
</head>
<body>

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
        <div>
            <h2 style="margin:0 0 2px 0;">Фасувальний лист</h2>
            <p style="margin:0;">Дата: <strong>{{ $date }}</strong></p>
        </div>
        <a href="{{ route('packaging.assembly', ['date' => $date]) }}"
           style="display:inline-flex; align-items:center; gap:8px; background:#16a34a; color:white; text-decoration:none; padding:8px 16px; border-radius:8px; font-weight:700; font-size:13px; no-print;">
            📦 Список упаковки по клієнтах →
        </a>
    </div>

    @if(!empty($missingPlans ?? []))
        <div style="background:#fee2e2; border:2px solid #ef4444; border-radius:8px; padding:10px 14px; margin-bottom:18px; color:#7f1d1d; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
            <div style="font-weight:900; font-size:12px; margin-bottom:5px; text-transform:uppercase;">⚠️ Не вистачає меню для деяких планів</div>
            @foreach($missingPlans as $mp)
                <div style="margin-bottom:3px; font-size:11px;">
                    <strong>{{ $mp['plan']->name }}</strong> — день №{{ $mp['day_number'] }} циклу не створено.
                    Зачеплено клієнтів: <strong>{{ $mp['orders_count'] }}</strong>
                    @if(!empty($mp['client_names']))
                        ({{ implode(', ', $mp['client_names']) }}@if($mp['orders_count'] > count($mp['client_names'])), …@endif)
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @if(!empty($clientComments))
        <div style="background:#fefce8; border:1px dashed #ca8a04; padding:8px 10px; margin-bottom:20px; font-size:11px;">
            <div style="font-weight:bold; text-transform:uppercase; margin-bottom:5px; color:#92400e;">Коментарі клієнтів:</div>
            @foreach($clientComments as $c)
                <div style="margin-bottom:2px; color:#78350f;">
                    • #{{ $c['id'] }} {{ $c['name'] }} ({{ $c['project'] }}, {{ $c['calories'] }} ккал): {{ $c['text'] }}
                </div>
            @endforeach
        </div>
    @endif

    {{-- ОБХІД ПО ПЛАНАХ МЕНЮ --}}
    @foreach($reportByPlan as $planId => $planBlock)
        @php
            $report             = $planBlock['report'];
            $cyclicTables       = array_filter($report, fn($t) => empty($t['is_individual']));
            $customClientTables = array_filter($report, fn($t) => !empty($t['is_custom_client']));
            $individualTables   = array_filter($report, fn($t) => !empty($t['is_individual']) && empty($t['is_custom_client']));
        @endphp

        <div style="margin-top:18px; margin-bottom:12px; padding:10px 14px; background:#f5f3ff; border:2px solid #c4b5fd; border-radius:8px; -webkit-print-color-adjust:exact; print-color-adjust:exact; page-break-inside:avoid;">
            <div style="font-size:9px; font-weight:800; color:#5b21b6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">План меню</div>
            <div style="font-size:16px; font-weight:900; color:#1e1b4b;">{{ $planBlock['plan']->name }}</div>
            <div style="font-size:10px; color:#5b21b6; margin-top:2px;">День циклу №{{ $planBlock['day_number'] }} з {{ $planBlock['plan']->cycle_days }}</div>
        </div>

    {{-- ЦИКЛІЧНІ СТРАВИ --}}
    @foreach($cyclicTables as $table)
        @php
            $mealLower = mb_strtolower(trim($table['meal']));
            $mealClass = 'meal-default';
            if (str_contains($mealLower, 'сніданок'))      $mealClass = 'meal-snidanok';
            elseif (str_contains($mealLower, 'перекус 1')) $mealClass = 'meal-perekus-1';
            elseif (str_contains($mealLower, 'обід'))      $mealClass = 'meal-obid';
            elseif (str_contains($mealLower, 'перекус 2')) $mealClass = 'meal-perekus-2';
            elseif (str_contains($mealLower, 'вечеря'))    $mealClass = 'meal-vecherya';
        @endphp

        <div class="meal-title {{ $mealClass }}">{{ $table['meal'] }}: {{ $table['dish_name'] }}</div>
        <table>
            <thead>
                <tr>
                    <th rowspan="2" style="width:200px;vertical-align:middle;">{{ $table['dish_name'] }}</th>
                    <th colspan="{{ count($table['columns']) }}" class="header-green">ПРОГРАМА (ККАЛ / КЛІЄНТ)</th>
                    <th rowspan="2" style="width:80px;vertical-align:middle;font-size:12px;font-weight:bold;">ЗАГАЛОМ</th>
                </tr>
                <tr>
                    @foreach($table['columns'] as $colKey => $colData)
                        <th class="kcal-{{ trim($colKey) }}">
                            {{ $colKey }} <span style="font-size:11px;font-weight:400;opacity:.85;">({{ ($colData['count'] ?? 0) + ($colData['custom_count'] ?? 0) }})</span>
                        </th>
                    @endforeach
                </tr>
                <tr>
                    <td class="portions-title">КІЛЬКІСТЬ ПОРЦІЙ</td>
                    @foreach($table['columns'] as $colData)
                        <td class="portions-cell">
                            {{ ($colData['count'] ?? 0) + ($colData['custom_count'] ?? 0) }}
                            @if(!empty($colData['projects']))
                                <div style="font-size:10px;font-weight:600;margin-top:2px;color:#111827 !important;">
                                    @foreach($colData['projects'] as $p){{ $p['name'] }}: {{ $p['count'] }}@if(!empty($p['custom_count']))<span style="color:#dc2626;font-weight:800;"> ({{ $p['custom_count'] }})</span>@endif<br>@endforeach
                                </div>
                            @endif
                        </td>
                    @endforeach
                    <td style="font-size:16px;font-weight:bold;">{{ collect($table['columns'])->sum(fn($c) => ($c['count'] ?? 0) + ($c['custom_count'] ?? 0)) }}</td>
                </tr>
            </thead>
            <tbody>
                @foreach($table['rows'] as $row)
                    <tr>
                        <td class="text-left" style="font-weight:bold;">{{ $row['original_name'] }}</td>
                        @php $rowTotal = 0; @endphp
                        @foreach($table['columns'] as $colKey => $colData)
                            @php $cell=$row['cells'][$colKey]??0; $val=is_array($cell)?($cell['val']??0):$cell; $rowTotal+=$val*($colData['count']??1); @endphp
                            <td style="font-weight:bold;font-size:13px;">@if(($colData['count'] ?? 0) > 0){{ $val }} г@else—@endif</td>
                        @endforeach
                        <td style="font-size:14px;font-weight:bold;">{{ round($rowTotal) }} г</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(!empty($table['individual_notes']))
            <div class="notes-box">
                <div class="notes-header">Індивідуальні заміни:</div>
                @foreach($table['individual_notes'] as $note)
                    <div class="note-row">• #{{ $note['id'] }} {{ $note['name'] }} ({{ $note['project'] }}, {{ $note['calories'] }} ккал): {{ $note['text'] }}</div>
                @endforeach
            </div>
        @endif
    @endforeach

    {{-- КАСТОМНІ КЛІЄНТИ (свапи інгредієнта / force-approved) — окремі картки, щоб фасовка бачила точно, що їм пакувати --}}
    @if(!empty($customClientTables))
        <div style="margin-top:20px; page-break-before:auto;">
            <div style="background:#ea580c; color:white; padding:6px 10px; font-weight:900; font-size:13px; text-transform:uppercase; margin-bottom:10px; border-radius:4px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                ⚠ Кастомні заміни (клієнти зі стандартного меню)
            </div>
            @foreach(array_values($customClientTables) as $table)
                <div style="border:2px solid #ea580c; border-radius:6px; overflow:hidden; margin-bottom:14px; page-break-inside:avoid;">
                    <div style="background:#ea580c; color:white; padding:6px 12px; font-size:13px; font-weight:900; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                        {{ $table['client_label'] }}
                        <span style="margin-left:10px; font-size:11px; font-weight:500; opacity:0.85;">{{ $table['project'] }}</span>
                        <span style="margin-left:8px; font-size:11px; font-weight:700; background:rgba(255,255,255,0.2); padding:1px 6px; border-radius:3px;">{{ $table['calories'] }} ккал</span>
                    </div>
                    <table style="width:100%; border-collapse:collapse; margin:0;">
                        <tbody>
                            <tr style="vertical-align:top;">
                                @foreach($table['meals'] as $meal)
                                    @php
                                        $mealLower = mb_strtolower(trim($meal['meal']));
                                        $mealColor = '#94a3b8';
                                        if (str_contains($mealLower, 'сніданок'))      $mealColor = '#14b8a6';
                                        elseif (str_contains($mealLower, 'перекус 1')) $mealColor = '#84cc16';
                                        elseif (str_contains($mealLower, 'обід'))      $mealColor = '#fb923c';
                                        elseif (str_contains($mealLower, 'перекус 2')) $mealColor = '#f472b6';
                                        elseif (str_contains($mealLower, 'вечеря'))    $mealColor = '#38bdf8';
                                    @endphp
                                    <td style="vertical-align:top; padding:0; border:1px solid #e5e7eb; width:{{ round(100 / count($table['meals'])) }}%;">
                                        <div style="background:{{ $mealColor }}; color:white; padding:4px 8px; font-weight:900; font-size:10px; text-transform:uppercase; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                            {{ $meal['meal'] }}
                                        </div>
                                        <div style="background:#fef3c7; padding:4px 8px; border-bottom:1px solid #fde68a; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                            <div style="font-weight:900; font-size:11px; color:#78350f;">{{ $meal['dish_name'] }}</div>
                                        </div>
                                        <table style="width:100%; border-collapse:collapse; margin:0; font-size:10px;">
                                            <tbody>
                                                @foreach($meal['rows'] as $row)
                                                    <tr>
                                                        <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; color:#374151;">{{ $row['name'] }}</td>
                                                        <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; color:#111827; width:45px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">{{ $row['weight'] }} г</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        @if(!empty($meal['notes']))
                                            <div style="padding:3px 6px; background:#fff7ed; font-size:9px; color:#7c2d12;">
                                                @foreach($meal['notes'] as $note)
                                                    <div>• {{ $note['text'] }}</div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ІНДИВІДУАЛЬНІ КЛІЄНТИ — одна картка на клієнта з усіма раціонами --}}
    @if(!empty($individualTables))
        <div style="margin-top:24px; page-break-before:auto;">
            <div style="background:#7c3aed; color:white; padding:6px 10px; font-weight:900; font-size:13px; text-transform:uppercase; margin-bottom:10px; border-radius:4px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                ★ Індивідуальні клієнти
            </div>
            @foreach(array_values($individualTables) as $table)
                <div style="border:2px solid #7c3aed; border-radius:6px; overflow:hidden; margin-bottom:14px; page-break-inside:avoid;">
                    {{-- Заголовок клієнта --}}
                    <div style="background:#7c3aed; color:white; padding:6px 12px; font-size:13px; font-weight:900; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                        {{ $table['client_label'] }}
                        <span style="margin-left:10px; font-size:11px; font-weight:500; opacity:0.85;">{{ $table['project'] }}</span>
                        <span style="margin-left:8px; font-size:11px; font-weight:700; background:rgba(255,255,255,0.2); padding:1px 6px; border-radius:3px;">{{ $table['calories'] }} ккал</span>
                    </div>
                    {{-- Прийоми їжі в рядок --}}
                    <table style="width:100%; border-collapse:collapse; margin:0;">
                        <tbody>
                            <tr style="vertical-align:top;">
                                @foreach($table['meals'] as $meal)
                                    @php
                                        $mealLower = mb_strtolower(trim($meal['meal']));
                                        $mealColor = '#94a3b8';
                                        if (str_contains($mealLower, 'сніданок'))      $mealColor = '#14b8a6';
                                        elseif (str_contains($mealLower, 'перекус 1')) $mealColor = '#84cc16';
                                        elseif (str_contains($mealLower, 'обід'))      $mealColor = '#fb923c';
                                        elseif (str_contains($mealLower, 'перекус 2')) $mealColor = '#f472b6';
                                        elseif (str_contains($mealLower, 'вечеря'))    $mealColor = '#38bdf8';
                                    @endphp
                                    <td style="vertical-align:top; padding:0; border:1px solid #e5e7eb; width:{{ round(100 / count($table['meals'])) }}%;">
                                        {{-- Прийом їжі --}}
                                        <div style="background:{{ $mealColor }}; color:white; padding:4px 8px; font-weight:900; font-size:10px; text-transform:uppercase; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                            {{ $meal['meal'] }}
                                        </div>
                                        {{-- Назва страви --}}
                                        <div style="background:#dcfce7; padding:4px 8px; border-bottom:1px solid #bbf7d0; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                            <div style="font-weight:900; font-size:11px; color:#052e16;">{{ $meal['dish_name'] }}</div>
                                        </div>
                                        {{-- Інгредієнти --}}
                                        <table style="width:100%; border-collapse:collapse; margin:0; font-size:10px;">
                                            <tbody>
                                                @foreach($meal['rows'] as $row)
                                                    <tr>
                                                        <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; color:#374151;">{{ $row['name'] }}</td>
                                                        <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; color:#111827; width:45px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">{{ $row['weight'] }} г</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        @if(!empty($meal['notes']))
                                            <div style="padding:3px 6px; background:#fff7ed; font-size:9px; color:#7c2d12; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                                @foreach($meal['notes'] as $note)
                                                    <div>• {{ $note['text'] }}</div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    @endif

    @endforeach
    {{-- кінець @foreach($reportByPlan) --}}

    <script>
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>