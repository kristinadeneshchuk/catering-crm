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

    <h2>Фасувальний лист</h2>
    <p>Дата: <strong>{{ $date }}</strong></p>

    @foreach($report as $table)
        @php
            $mealLower = mb_strtolower(trim($table['meal']));
            $mealClass = 'meal-default';
            
            if (str_contains($mealLower, 'сніданок')) {
                $mealClass = 'meal-snidanok';
            } elseif (str_contains($mealLower, 'перекус 1')) {
                $mealClass = 'meal-perekus-1';
            } elseif (str_contains($mealLower, 'обід')) {
                $mealClass = 'meal-obid';
            } elseif (str_contains($mealLower, 'перекус 2')) {
                $mealClass = 'meal-perekus-2';
            } elseif (str_contains($mealLower, 'вечеря')) {
                $mealClass = 'meal-vecherya';
            }
        @endphp

        <div class="meal-title {{ $mealClass }}">{{ $table['meal'] }}: {{ $table['dish_name'] }}</div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2" style="width: 200px; vertical-align: middle;">{{ $table['dish_name'] }}</th>
                    <th colspan="{{ count($table['columns']) }}" class="header-green">ПРОГРАМА (ККАЛ / КЛІЄНТ)</th>
                </tr>
                <tr>
                    @foreach($table['columns'] as $colKey => $colData)
                        @php $kcalClass = 'kcal-' . trim($colKey); @endphp
                        <th class="{{ $kcalClass }}">
                            {{ $colKey }}
                            <span style="font-size:11px; font-weight:400; opacity:.85;">({{ $colData['count'] }})</span>
                        </th>
                    @endforeach
                </tr>
                <tr>
                    <td class="portions-title">КІЛЬКІСТЬ ПОРЦІЙ</td>
                    @foreach($table['columns'] as $colData)
                        <td class="portions-cell">
                            {{ $colData['count'] }}
                            @if(!empty($colData['projects']))
                                <div style="font-size:10px; font-weight:400; margin-top:2px; opacity:.85;">
                                    @foreach($colData['projects'] as $p)
                                        {{ $p['name'] }}: {{ $p['count'] }}<br>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($table['rows'] as $row)
                    <tr>
                        <td class="text-left" style="font-weight: bold;">{{ $row['original_name'] }}</td>
                        @foreach($table['columns'] as $colKey => $colData)
                            <td style="font-weight: bold; font-size: 13px;">
                                @php $cell = $row['cells'][$colKey] ?? 0; @endphp
                                {{ is_array($cell) ? ($cell['val'] ?? 0) : $cell }} г
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if(!empty($table['individual_notes']))
            <div class="notes-box">
                <div class="notes-header">Індивідуальні заміни:</div>
                @foreach($table['individual_notes'] as $note)
                    <div class="note-row">
                        • #{{ $note['id'] }} {{ $note['name'] }} ({{ $note['project'] }}, {{ $note['calories'] }} ккал): {{ $note['text'] }}
                    </div>
                @endforeach
            </div>
        @endif

    @endforeach

    <script>
        setTimeout(function() {
            window.print();
        }, 500);
    </script>
</body>
</html>