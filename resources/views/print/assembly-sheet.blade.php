<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Збірний лист на {{ $date }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            color: #111;
            background: #fff;
            margin: 0;
            padding: 12px 16px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        h1 { font-size: 16px; font-weight: bold; margin: 0 0 10px; }

        /* ===== ЗВЕДЕНА ТАБЛИЦЯ ===== */
        .summary-table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 16px;
        }
        .summary-table th,
        .summary-table td {
            border: 1px solid #555;
            padding: 3px 5px;
            text-align: center;
            white-space: nowrap;
        }
        .summary-table th { background: #e5e7eb; font-weight: bold; }
        .row-total   { background: #d1d5db !important; font-weight: bold; }
        .row-evening { background: #fef3c7 !important; }
        .row-morning { background: #dbeafe !important; }

        /* ===== КОЛЬОРИ КАЛОРАЖУ ===== */
        .cal-950  { background: #fef08a !important; }
        .cal-1200 { background: #d9f99d !important; }
        .cal-1500 { background: #86efac !important; }
        .cal-1800 { background: #fde68a !important; }
        .cal-2100 { background: #fed7aa !important; }
        .cal-2500 { background: #fda4af !important; }
        .cal-3000 { background: #93c5fd !important; }
        .cal-3500 { background: #67e8f9 !important; }
        .cal-other { background: #e5e7eb !important; }

        /* ===== ДВОКОЛОНКОВИЙ СПИСОК ===== */
        .clients-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        .clients-section h2 {
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin: 0 0 6px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        .evening-title { background: #fef3c7; }
        .morning-title { background: #dbeafe; }

        .client-table {
            border-collapse: collapse;
            width: 100%;
        }
        .client-table th {
            border: 1px solid #555;
            padding: 3px 6px;
            background: #f3f4f6;
            font-size: 10px;
            text-align: center;
        }
        .client-table td {
            border: 1px solid #999;
            padding: 3px 6px;
            vertical-align: top;
        }
        .client-id-cell {
            font-weight: bold;
            text-align: center;
            width: 50px;
            font-size: 12px;
        }
        .changes-cell { font-size: 10px; }
        .comment-text { color: #6b7280; font-style: italic; margin-top: 2px; }

        /* ===== ПРИМІТКИ ===== */
        .notes-section {
            margin-top: 20px;
            border-top: 2px solid #111;
            padding-top: 8px;
        }
        .notes-section h2 { font-size: 13px; font-weight: bold; margin-bottom: 40px; }

        @media print {
            body { padding: 6px 10px; }
            .no-print { display: none !important; }
            .clients-grid { gap: 10px; }
        }
    </style>
</head>
<body>

    {{-- КНОПКА ДРУКУ --}}
    <div class="no-print" style="margin-bottom:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        <button onclick="window.print()" style="background:#f97316;color:#fff;border:none;border-radius:6px;padding:6px 18px;font-weight:bold;font-size:13px;cursor:pointer;">
            Роздрукувати
        </button>
        <a href="?date={{ \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d') }}"
           style="background:#f1f5f9;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:5px 14px;text-decoration:none;font-size:12px;">
            ← Попередній день
        </a>
        <span style="font-size:12px;color:#6b7280;">{{ $date }}</span>
        <a href="?date={{ \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d') }}"
           style="background:#f1f5f9;color:#374151;border:1px solid #d1d5db;border-radius:6px;padding:5px 14px;text-decoration:none;font-size:12px;">
            Наступний день →
        </a>
    </div>

    <h1>Збірний лист на {{ $date }}</h1>

    {{-- ЗВЕДЕНА ТАБЛИЦЯ --}}
    @php
        $calCount   = count($calorieLevels);
        $halfCount  = (int) ceil($calCount / 2);
        $firstHalf  = array_slice($calorieLevels, 0, $halfCount);
        $secondHalf = array_slice($calorieLevels, $halfCount);

        // Підсумки для кожної половини (для заголовків Вечір/Ранок)
        $firstHalfCols  = count($firstHalf) * 2;
        $secondHalfCols = count($secondHalf) * 2;

        function calBgColor(int $cal): string {
            if ($cal <= 950)  return '#86efac';
            if ($cal <= 1200) return '#4ade80';
            if ($cal <= 1500) return '#fde047';
            if ($cal <= 1800) return '#fb923c';
            if ($cal <= 2100) return '#f97316';
            if ($cal <= 2500) return '#ef4444';
            if ($cal <= 3000) return '#b91c1c';
            return '#7f1d1d';
        }
        function calTextColor(int $cal): string {
            return $cal >= 3000 ? '#fff' : '#111';
        }
    @endphp
    <table class="summary-table">
        <thead>
            {{-- Рядок 1: Всього --}}
            <tr>
                <th colspan="{{ $calCount * 2 }}" style="font-size:13px; padding:5px;">
                    Всього: {{ $totalAll }} ({{ $totalInd }})
                </th>
            </tr>
            {{-- Рядок 2: Вечір / Ранок --}}
            <tr>
                @if($firstHalfCols > 0)
                    <th colspan="{{ $firstHalfCols }}" style="background:#fef3c7; font-size:11px;">
                        Вечір: {{ $totalEvening }} ({{ $totalEveningInd }})
                    </th>
                @endif
                @if($secondHalfCols > 0)
                    <th colspan="{{ $secondHalfCols }}" style="background:#dbeafe; font-size:11px;">
                        Ранок: {{ $totalMorning }} ({{ $totalMorningInd }})
                    </th>
                @endif
            </tr>
            {{-- Рядок 3: Калораж (кольоровий) --}}
            <tr>
                @foreach($calorieLevels as $cal)
                    <th colspan="2" style="background:{{ calBgColor($cal) }}; color:{{ calTextColor($cal) }}; font-size:11px; padding:3px 4px;">
                        {{ $cal }}
                    </th>
                @endforeach
            </tr>
            {{-- Рядок 4: Загальна кількість по калоражу --}}
            <tr>
                @foreach($calorieLevels as $cal)
                    @php $s = $stats[$cal]; @endphp
                    <td colspan="2" style="text-align:center; font-weight:bold; font-size:11px; background:#f9fafb; border:1px solid #555;">
                        {{ $s['total'] }} ({{ $s['total_ind'] }})
                    </td>
                @endforeach
            </tr>
        </thead>
        <tbody>
            {{-- Рядок 5: Веч / Ран заголовки --}}
            <tr>
                @foreach($calorieLevels as $cal)
                    <th style="font-size:9px; background:#fef9c3;">Веч</th>
                    <th style="font-size:9px; background:#eff6ff;">Ран</th>
                @endforeach
            </tr>
            {{-- Рядок 6: Значення Веч/Ран --}}
            <tr class="row-total">
                @foreach($calorieLevels as $cal)
                    @php $s = $stats[$cal]; @endphp
                    <td style="background:#fef9c3;">
                        {{ $s['evening'] }}<br>
                        <span style="font-size:9px; color:#555;">({{ $s['evening_ind'] }})</span>
                    </td>
                    <td style="background:#eff6ff;">
                        {{ $s['morning'] }}<br>
                        <span style="font-size:9px; color:#555;">({{ $s['morning_ind'] }})</span>
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    @php
        function calClass(int $cal): string {
            if ($cal <= 950)  return 'cal-950';
            if ($cal <= 1200) return 'cal-1200';
            if ($cal <= 1500) return 'cal-1500';
            if ($cal <= 1800) return 'cal-1800';
            if ($cal <= 2100) return 'cal-2100';
            if ($cal <= 2500) return 'cal-2500';
            if ($cal <= 3000) return 'cal-3000';
            if ($cal <= 3500) return 'cal-3500';
            return 'cal-other';
        }
    @endphp

    {{-- ДВОКОЛОНКОВИЙ СПИСОК КЛІЄНТІВ З ІНДИВІДУАЛЬНИМИ ЗМІНАМИ --}}
    <div class="clients-grid">

        {{-- ВЕЧІРНІ --}}
        <div class="clients-section">
            <h2 class="evening-title">Вечірні ({{ $eveningRows->count() }})</h2>
            @if($eveningRows->isEmpty())
                <p style="color:#9ca3af; font-style:italic;">Немає індивідуальних змін</p>
            @else
                <table class="client-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th style="text-align:left;">На що замінено</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($eveningRows as $row)
                            <tr>
                                <td class="client-id-cell {{ calClass($row['calories']) }}">
                                    {{ $row['client_id'] }}
                                </td>
                                <td class="changes-cell">
                                    @if($row['changes'])
                                        {{ $row['changes'] }}
                                    @endif
                                    @if($row['comment'])
                                        <div class="comment-text">{{ $row['comment'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- РАНКОВІ --}}
        <div class="clients-section">
            <h2 class="morning-title">Ранкові ({{ $morningRows->count() }})</h2>
            @if($morningRows->isEmpty())
                <p style="color:#9ca3af; font-style:italic;">Немає індивідуальних змін</p>
            @else
                <table class="client-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th style="text-align:left;">На що замінено</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($morningRows as $row)
                            <tr>
                                <td class="client-id-cell {{ calClass($row['calories']) }}">
                                    {{ $row['client_id'] }}
                                </td>
                                <td class="changes-cell">
                                    @if($row['changes'])
                                        {{ $row['changes'] }}
                                    @endif
                                    @if($row['comment'])
                                        <div class="comment-text">{{ $row['comment'] }}</div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

    </div>

    {{-- ПРИМІТКИ --}}
    <div class="notes-section">
        <h2>Примітки:</h2>
    </div>

</body>
</html>
