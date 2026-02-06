<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Фасувальний лист {{ $date }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; }
        .meal-title { background-color: #f97316; color: white; padding: 5px; font-weight: bold; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
        th, td { border: 1px solid #ccc; padding: 4px; text-align: center; }
        th.header-green { background-color: #4ade80; color: black; }
        th.header-light { background-color: #dcfce7; }
        .text-left { text-align: left; }
        
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
    </style>
</head>
<body>

    <h2>Фасувальний лист</h2>
    <p>Дата: <strong>{{ $date }}</strong></p>

    @foreach($report as $table)
        <div class="meal-title">{{ strtoupper($table['meal']) }}: {{ strtoupper($table['dish_name']) }}</div>

        <table>
            <thead>
                <tr>
                    <th rowspan="2" style="width: 200px;">{{ $table['dish_name'] }}</th>
                    <th colspan="{{ count($table['columns']) }}" class="header-green">ПРОГРАМА (ККАЛ / КЛІЄНТ)</th>
                </tr>
                <tr>
                    @foreach($table['columns'] as $colKey => $colData)
                        <th class="header-light" style="font-size: 10px;">{{ $colKey }}</th>
                    @endforeach
                </tr>
                <tr>
                    <td><strong>КІЛЬКІСТЬ ПОРЦІЙ</strong></td>
                    @foreach($table['columns'] as $colData)
                        <td class="header-light"><strong>{{ $colData['count'] }}</strong></td>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($table['rows'] as $row)
                    <tr>
                        <td class="text-left">{{ $row['original_name'] }}</td>
                        @foreach($row['cells'] as $cell)
                            <td>{{ $cell['val'] }} г</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        {{-- 🔥 БЛОК ЗАМІН 🔥 --}}
        @if(!empty($table['individual_notes']))
            <div class="notes-box">
                <div class="notes-header">⚠️ Індивідуальні заміни:</div>
                @foreach($table['individual_notes'] as $note)
                    <div>{{ $note }}</div>
                @endforeach
            </div>
        @endif

    @endforeach

    <script>
        window.print();
    </script>
</body>
</html>