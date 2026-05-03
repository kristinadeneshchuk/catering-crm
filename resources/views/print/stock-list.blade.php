<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Списання склад — {{ $targetDateFormatted }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }

        body { font-family: Arial, Helvetica, sans-serif; font-size: 13px; background: #f1f5f9; color: #0f172a; }

        .no-print { padding: 24px; text-align: center; }
        .no-print button {
            background: #1e293b; color: white; border: none;
            padding: 14px 36px; border-radius: 10px; font-size: 15px;
            font-weight: 900; cursor: pointer; letter-spacing: 1.5px; text-transform: uppercase;
        }
        .no-print button:hover { background: #334155; }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: white;
            padding: 14mm 12mm 14mm 12mm;
        }

        /* Шапка */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            border-bottom: 3px solid #0f172a;
            padding-bottom: 6mm;
            margin-bottom: 8mm;
        }
        .header-left h1 {
            font-size: 22px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: -0.5px;
        }
        .header-left p {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }
        .header-right {
            text-align: right;
        }
        .header-right .date-label {
            font-size: 10px;
            color: #94a3b8;
            text-transform: uppercase;
            font-weight: 600;
        }
        .header-right .date-val {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
        }
        .day-badge {
            display: inline-block;
            background: #fde047;
            color: #000;
            font-size: 10px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: 4px;
            margin-top: 3px;
        }

        /* Таблиця */
        .ingredient-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0;
        }

        .ingredient-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 8px 4px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .ingredient-row:nth-child(3n+1) { padding-left: 0; }
        .ingredient-row:nth-child(3n+2) { padding-left: 12px; border-left: 1px solid #e2e8f0; }
        .ingredient-row:nth-child(3n)   { padding-left: 12px; border-left: 1px solid #e2e8f0; }

        .ing-name {
            font-size: 11px;
            color: #334155;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-right: 6px;
        }

        .ing-weight {
            font-size: 12px;
            font-weight: 800;
            color: #0f172a;
            white-space: nowrap;
        }

        .ing-unit {
            font-size: 10px;
            color: #94a3b8;
            margin-left: 1px;
        }

        /* Підпис */
        .footer {
            margin-top: 10mm;
            border-top: 1px solid #e2e8f0;
            padding-top: 5mm;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .footer p { font-size: 10px; color: #94a3b8; }
        .footer .total {
            font-size: 12px;
            font-weight: 700;
            color: #334155;
        }

        @media print {
            body { background: white !important; }
            .no-print { display: none !important; }
            .page { margin: 0 !important; }
        }
        @page { size: A4; margin: 0; }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">ДРУКУВАТИ СПИСОК СПИСАННЯ</button>
</div>

<div class="page">

    <div class="header">
        <div class="header-left">
            <h1>Очікуване списання</h1>
            <p>Брутто-вага інгредієнтів для приготування</p>
        </div>
        <div class="header-right">
            <div class="date-label">Дата приготування</div>
            <div class="date-val">{{ $date }}</div>
            <div>
                @if(!empty($planSummaries))
                    @foreach($planSummaries as $ps)
                        <span class="day-badge">{{ $ps['plan']->name }}: день {{ $ps['day_number'] }} → {{ $targetDateFormatted }}</span>
                    @endforeach
                @endif
            </div>
        </div>
    </div>

    @if(!empty($missingPlans ?? []))
        <div style="background:#fee2e2; border:2px solid #ef4444; border-radius:8px; padding:10px 14px; margin-bottom:18px; color:#7f1d1d; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
            <div style="font-weight:900; font-size:12px; margin-bottom:5px; text-transform:uppercase;">⚠️ Не вистачає меню для деяких планів</div>
            @foreach($missingPlans as $mp)
                <div style="margin-bottom:3px; font-size:11px;">
                    <strong>{{ $mp['plan']->name }}</strong> — день №{{ $mp['day_number'] }} циклу не створено.
                    Зачеплено клієнтів: <strong>{{ $mp['orders_count'] }}</strong>
                </div>
            @endforeach
        </div>
    @endif

    <div class="ingredient-grid">
        @foreach($ingredients as $name => $weight)
            @php
                $kg  = $weight / 1000;
                $display = $kg >= 1
                    ? number_format($kg, 2, '.', ' ') . ' <span class="ing-unit">кг</span>'
                    : number_format($weight, 0, '.', ' ') . ' <span class="ing-unit">г</span>';
            @endphp
            <div class="ingredient-row">
                <span class="ing-name">{{ $name }}</span>
                <span class="ing-weight">{!! $display !!}</span>
            </div>
        @endforeach
    </div>

    <div class="footer">
        <p>Сформовано: {{ now()->format('d.m.Y H:i') }}</p>
        <span class="total">Всього позицій: {{ count($ingredients) }}</span>
    </div>

</div>

</body>
</html>
