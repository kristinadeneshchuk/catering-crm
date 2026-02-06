<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Фасувальний лист {{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</title>
    <style>
        body { font-family: sans-serif; margin: 20px; color: black; background: white; }
        
        /* Гарантія кольорів */
        * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        /* Стилі таблиці (Ваш дизайн) */
        .matrix-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; border: 1px solid #000; }
        .matrix-table th, .matrix-table td { border: 1px solid #000; padding: 4px; text-align: center; color: black; }
        
        .header-top { background: #4ade80; font-weight: 900; text-transform: uppercase; }
        .header-kcal { background: #f3f4f6; font-weight: 700; font-size: 10px; }
        .row-label { text-align: left; font-weight: 700; background: #f9fafb; padding-left: 10px; width: 200px; }
        .row-count { background: #dcfce7; font-weight: 900; font-size: 14px; }

        /* Бейдж */
        .meal-badge { 
            background: #ea580c; color: white; padding: 5px 15px; 
            border-radius: 6px; font-size: 16px; font-weight: 900; 
            text-transform: uppercase; display: inline-block; margin-bottom: 10px;
        }

        /* Заміни */
        .replacements-container { 
            font-size: 11px; color: #7f1d1d; background: #fee2e2; 
            padding: 10px; border-radius: 6px; margin-top: -15px; 
            margin-bottom: 30px; border: 1px solid #fca5a5; 
        }

        .meal-section { page-break-inside: avoid; margin-bottom: 30px; }

        /* Кнопка друку (ховається на папері) */
        @media print { .no-print { display: none; } }
        .btn-print { 
            background: #000; color: white; padding: 10px 20px; 
            border-radius: 8px; font-weight: bold; cursor: pointer; border: none;
        }
    </style>
</head>
<body>

    <div class="no-print" style="text-align: right; margin-bottom: 20px;">
        <button onclick="window.print()" class="btn-print">🖨️ ДРУКУВАТИ</button>
    </div>

    <div style="margin-bottom: 20px;">
        <h1 style="margin: 0;">Фасувальний лист</h1>
        <div>Дата: <strong>{{ \Carbon\Carbon::parse($date)->format('d.m.Y') }}</strong></div>
    </div>

    @foreach($report as $table)
        <div class="meal-section">
            <div class="meal-badge">
                {{ $table['meal'] }}: {{ $table['dish_name'] }}
            </div>

            <table class="matrix-table">
                <thead>
                    <tr class="header-top">
                        <th rowspan="2" class="row-label">{{ $table['dish_name'] }}</th>
                        <th colspan="{{ count($table['columns']) }}">Програма (ккал / Клієнт)</th>
                    </tr>
                    <tr class="header-kcal">
                        @foreach($table['columns'] as $label => $info)
                            <th>{{ $label }}</th>
                        @endforeach
                    </tr>
                    <tr class="row-count">
                        <td class="row-label">КІЛЬКІСТЬ ПОРЦІЙ</td>
                        @foreach($table['columns'] as $info)
                            <td>{{ $info['count'] }}</td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($table['rows'] as $row)
                        <tr>
                            <td class="row-label">{{ $row['original_name'] }}</td>
                            @foreach($table['columns'] as $key => $info)
                                <td>
                                    <strong>{{ $row['cells'][$key]['val'] }}</strong> г
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if(!empty($table['individual_notes']))
                <div class="replacements-container">
                    <div style="font-weight: 900; text-transform: uppercase; margin-bottom: 5px;">
                        ⚠️ Індивідуальні заміни:
                    </div>
                    @foreach(array_unique($table['individual_notes']) as $note)
                        <div>{{ $note }}</div>
                    @endforeach
                </div>
            @endif
        </div>
    @endforeach
    
    <script>
        // Автоматично відкрити діалог друку
        window.onload = function() { window.print(); }
    </script>
</body>
</html>