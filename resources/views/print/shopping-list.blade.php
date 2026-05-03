<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Список покупок — {{ $date }}</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        body { font-family: Arial, Helvetica, sans-serif; font-size:12px; background:#f1f5f9; color:#0f172a; }

        .no-print { padding:20px; text-align:center; }
        .no-print button {
            background:#1e293b; color:white; border:none;
            padding:14px 36px; border-radius:10px; font-size:15px;
            font-weight:900; cursor:pointer; letter-spacing:1.5px; text-transform:uppercase;
        }

        .page { width:210mm; min-height:297mm; margin:16px auto; background:white; padding:12mm 12mm 12mm 12mm; }

        /* Шапка */
        .header { display:flex; justify-content:space-between; align-items:flex-end; border-bottom:3px solid #0f172a; padding-bottom:5mm; margin-bottom:7mm; }
        .header h1 { font-size:20px; font-weight:900; text-transform:uppercase; }
        .header p  { font-size:11px; color:#64748b; margin-top:2px; }
        .header-right { text-align:right; }
        .header-right .big { font-size:20px; font-weight:900; }
        .day-badge { display:inline-block; background:#fde047; color:#000; font-size:10px; font-weight:800; padding:2px 8px; border-radius:4px; margin-top:3px; }

        /* Статистика */
        .stats { display:flex; gap:12px; margin-bottom:8mm; }
        .stat-box { flex:1; border:1px solid #e2e8f0; border-radius:8px; padding:8px 12px; text-align:center; }
        .stat-box .num { font-size:22px; font-weight:900; }
        .stat-box .lbl { font-size:9px; color:#6b7280; text-transform:uppercase; font-weight:600; margin-top:2px; }
        .stat-buy  { border-color:#fca5a5; background:#fef2f2; }
        .stat-buy .num { color:#dc2626; }
        .stat-ok   { border-color:#bbf7d0; background:#f0fdf4; }
        .stat-ok .num  { color:#16a34a; }
        .stat-total .num { color:#0f172a; }

        /* Секція "Купити" */
        .section-title { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4mm; padding-bottom:2mm; border-bottom:1.5px solid #e2e8f0; display:flex; align-items:center; gap:6px; }
        .section-title.buy  { color:#dc2626; border-color:#fca5a5; }
        .section-title.ok   { color:#16a34a; border-color:#bbf7d0; margin-top:6mm; }

        /* Таблиця купівлі */
        .buy-table { width:100%; border-collapse:collapse; }
        .buy-table th { font-size:9px; color:#64748b; text-transform:uppercase; font-weight:700; padding:4px 6px; text-align:left; border-bottom:1px solid #e2e8f0; }
        .buy-table th.r { text-align:right; }
        .buy-table td { padding:5px 6px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .buy-table td.r { text-align:right; }
        .buy-table tr:nth-child(even) td { background:#fafafa; }

        .name-cell { font-weight:700; font-size:12px; }
        .need-cell { font-size:11px; color:#64748b; }
        .stock-neg { color:#dc2626; font-weight:700; }
        .stock-pos { color:#6b7280; }
        .buy-cell  { font-size:14px; font-weight:900; color:#dc2626; }
        .buy-cell.warn { color:#d97706; }

        /* Таблиця "достатньо" */
        .ok-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:0; }
        .ok-row { display:flex; justify-content:space-between; align-items:center; padding:3px 6px; border-bottom:1px solid #f1f5f9; font-size:10px; }
        .ok-row:nth-child(3n+2), .ok-row:nth-child(3n) { border-left:1px solid #f1f5f9; }
        .ok-name { color:#374151; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ok-val  { color:#16a34a; font-weight:700; white-space:nowrap; margin-left:4px; }

        /* Підвал */
        .footer { margin-top:8mm; border-top:1px solid #e2e8f0; padding-top:4mm; display:flex; justify-content:space-between; }
        .footer p { font-size:9px; color:#94a3b8; }

        @media print {
            body { background:white !important; }
            .no-print { display:none !important; }
            .page { margin:0 !important; }
        }
        @page { size:A4; margin:0; }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">ДРУКУВАТИ СПИСОК ПОКУПОК</button>
</div>

<div class="page">

    <div class="header">
        <div>
            <h1>Список покупок</h1>
            <p>Брутто-вага з урахуванням складських залишків</p>
        </div>
        <div class="header-right">
            <div class="big">{{ $date }}</div>
            <div>
                @if(!empty($planSummaries))
                    @foreach($planSummaries as $ps)
                        <span class="day-badge">{{ $ps['plan']->name }}: {{ $ps['day_number'] }}-й день з {{ $ps['plan']->cycle_days }}</span>
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
                    @if(!empty($mp['client_names']))
                        ({{ implode(', ', $mp['client_names']) }}@if($mp['orders_count'] > count($mp['client_names'])), …@endif)
                    @endif
                </div>
            @endforeach
        </div>
    @endif

    @php
        $toBuy   = array_values(array_filter($shoppingList, fn($r) => !$r['enough']));
        $enough  = array_values(array_filter($shoppingList, fn($r) => $r['enough']));
        $fmt = fn($v, $u) => number_format($v, $v < 10 ? 2 : ($v < 100 ? 1 : 0), '.', ' ') . ' ' . $u;
    @endphp

    {{-- Статистика --}}
    <div class="stats">
        <div class="stat-box stat-buy">
            <div class="num">{{ count($toBuy) }}</div>
            <div class="lbl">Потрібно купити</div>
        </div>
        <div class="stat-box stat-ok">
            <div class="num">{{ count($enough) }}</div>
            <div class="lbl">Є на складі</div>
        </div>
        <div class="stat-box stat-total">
            <div class="num">{{ count($shoppingList) }}</div>
            <div class="lbl">Всього позицій</div>
        </div>
    </div>

    {{-- Треба купити --}}
    @if(!empty($toBuy))
    <div class="section-title buy">🛒 Потрібно купити</div>
    <table class="buy-table">
        <thead>
            <tr>
                <th>Продукт</th>
                <th class="r">Потреба</th>
                <th class="r">На складі</th>
                <th class="r">Купити</th>
            </tr>
        </thead>
        <tbody>
            @foreach($toBuy as $row)
            <tr>
                <td class="name-cell">{{ $row['name'] }}</td>
                <td class="r need-cell">{{ $fmt($row['need'], $row['unit']) }}</td>
                <td class="r {{ $row['stock'] < 0 ? 'stock-neg' : 'stock-pos' }}">{{ $fmt($row['stock'], $row['unit']) }}</td>
                <td class="r">
                    <span class="buy-cell {{ $row['stock'] >= 0 ? 'warn' : '' }}">{{ $fmt($row['to_buy'], $row['unit']) }}</span>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    @endif

    {{-- Достатньо --}}
    @if(!empty($enough))
    <div class="section-title ok">✓ Достатньо на складі</div>
    <div class="ok-grid">
        @foreach($enough as $row)
        <div class="ok-row">
            <span class="ok-name">{{ $row['name'] }}</span>
            <span class="ok-val">{{ $fmt($row['stock'], $row['unit']) }}</span>
        </div>
        @endforeach
    </div>
    @endif

    <div class="footer">
        <p>Сформовано: {{ now()->format('d.m.Y H:i') }}</p>
        <p>Список покупок на {{ $date }}</p>
    </div>

</div>
</body>
</html>
