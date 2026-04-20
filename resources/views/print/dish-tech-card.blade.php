<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Техкарта — {{ $dish->name }}</title>
    <style>
        * {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            background: #f1f5f9;
            color: #0f172a;
        }

        .no-print {
            padding: 16px 24px;
            background: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .no-print button {
            background: #f59e0b;
            color: #1e293b;
            border: none;
            padding: 10px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        .no-print button:hover { background: #fbbf24; }

        .no-print span {
            color: #94a3b8;
            font-size: 13px;
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: white;
            padding: 14mm 12mm 12mm;
        }

        /* ── HEADER ── */
        .tc-header {
            border-bottom: 3px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }

        .tc-title {
            font-size: 9px;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #64748b;
            margin-bottom: 3px;
        }

        .tc-dish-name {
            font-size: 20px;
            font-weight: 900;
            color: #0f172a;
            line-height: 1.2;
        }

        .tc-meta {
            display: flex;
            gap: 20px;
            margin-top: 6px;
            font-size: 10px;
            color: #475569;
        }

        .tc-meta-item strong { color: #0f172a; }

        .badge {
            display: inline-block;
            padding: 1px 7px;
            border-radius: 99px;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }

        .badge-pf   { background: #fef3c7; color: #92400e; }
        .badge-dish { background: #dbeafe; color: #1e40af; }

        /* ── КБЖУ SUMMARY ── */
        .kbju-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 6px;
            margin: 10px 0;
        }

        .kbju-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 6px 8px;
            text-align: center;
        }

        .kbju-card .label {
            font-size: 8px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .kbju-card .value {
            font-size: 15px;
            font-weight: 800;
            color: #0f172a;
        }

        .kbju-card .unit {
            font-size: 8px;
            color: #94a3b8;
        }

        .kbju-card.kcal { border-color: #f59e0b; background: #fffbeb; }
        .kbju-card.kcal .value { color: #d97706; }

        .kbju-sub {
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
            margin-top: 1px;
        }

        /* ── TABLE ── */
        .section-title {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #64748b;
            margin: 12px 0 5px;
            padding-bottom: 3px;
            border-bottom: 1px solid #e2e8f0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead th {
            background: #0f172a;
            color: #f8fafc;
            font-size: 8.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            padding: 5px 4px;
            text-align: center;
        }

        thead th:first-child { text-align: left; padding-left: 6px; }

        tbody tr:nth-child(even) { background: #f8fafc; }

        tbody td {
            padding: 4px 4px;
            font-size: 10px;
            border-bottom: 1px solid #f1f5f9;
            text-align: center;
            vertical-align: middle;
        }

        tbody td:first-child { text-align: left; }

        /* НФ header row */
        tr.pf-row td {
            background: #f0f9ff !important;
            font-weight: 700;
            font-size: 10px;
            color: #0369a1;
            border-top: 1px solid #bae6fd;
            border-bottom: 1px solid #bae6fd;
        }

        tr.pf-row td .pf-badge {
            display: inline-block;
            background: #0ea5e9;
            color: white;
            font-size: 7px;
            font-weight: 800;
            padding: 1px 5px;
            border-radius: 3px;
            margin-right: 4px;
            letter-spacing: 0.5px;
        }

        /* Sub-ingredients (expanded НФ) */
        tr.sub-row td {
            background: #fafafa !important;
            color: #64748b;
            font-size: 9px;
            border-bottom: 1px solid #f1f5f9;
        }

        tr.sub-row td:first-child {
            padding-left: 20px;
        }

        .sub-indent { padding-left: 28px !important; }

        /* Total row */
        tr.total-row td {
            font-weight: 800;
            background: #1e293b !important;
            color: #f8fafc;
            font-size: 10px;
            padding: 5px 4px;
        }

        tr.per100-row td {
            font-weight: 700;
            background: #334155 !important;
            color: #cbd5e1;
            font-size: 9px;
            padding: 4px 4px;
        }

        /* ── RECIPE ── */
        .recipe-box {
            margin-top: 14px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 10px 12px;
            font-size: 10px;
            line-height: 1.6;
            color: #334155;
        }

        .recipe-box h2, .recipe-box h3 { font-size: 11px; margin-bottom: 4px; }
        .recipe-box ul, .recipe-box ol { padding-left: 18px; }
        .recipe-box p { margin-bottom: 6px; }

        /* ── FOOTER ── */
        .tc-footer {
            margin-top: 14px;
            font-size: 8px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 6px;
            display: flex;
            justify-content: space-between;
        }

        @media print {
            body { background: white; }
            .no-print { display: none !important; }
            .page { margin: 0; padding: 10mm 10mm 8mm; box-shadow: none; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">Друкувати / Зберегти PDF</button>
    <span>{{ $dish->name }}</span>
</div>

<div class="page">

    {{-- ── HEADER ── --}}
    <div class="tc-header">
        <div class="tc-title">Технологічна карта страви</div>
        <div class="tc-dish-name">
            {{ $dish->name }}
            @if($dish->is_semi_finished)
                <span class="badge badge-pf" style="font-size:11px;vertical-align:middle;">НФ</span>
            @else
                <span class="badge badge-dish" style="font-size:11px;vertical-align:middle;">Страва</span>
            @endif
        </div>
        <div class="tc-meta">
            @if($dish->group)
                <span><strong>Група:</strong> {{ $dish->group }}</span>
            @endif
            <span><strong>Вага виходу:</strong> {{ $dish->base_weight_g }} г</span>
            <span><strong>Вхід (нетто):</strong> {{ $dish->input_weight }} г</span>
            @if($dish->packaging_type)
                <span><strong>Упаковка:</strong> {{ $dish->packaging_type }}</span>
            @endif
            <span><strong>Собівартість:</strong> ₴ {{ number_format($dish->total_cost, 2) }}</span>
        </div>
    </div>

    {{-- ── КБЖУ SUMMARY ── --}}
    @php
        $w = $dish->base_weight_g > 0 ? $dish->base_weight_g : 1;
    @endphp
    <div class="kbju-grid">
        <div class="kbju-card kcal">
            <div class="label">Калорії</div>
            <div class="value">{{ number_format($dish->total_kcal, 0) }}</div>
            <div class="unit">ккал</div>
            <div class="kbju-sub">{{ number_format($dish->total_kcal / $w * 100, 1) }} / 100г</div>
        </div>
        <div class="kbju-card">
            <div class="label">Білки</div>
            <div class="value">{{ number_format($dish->total_prot, 1) }}</div>
            <div class="unit">г</div>
            <div class="kbju-sub">{{ number_format($dish->total_prot / $w * 100, 1) }} / 100г</div>
        </div>
        <div class="kbju-card">
            <div class="label">Жири</div>
            <div class="value">{{ number_format($dish->total_fat, 1) }}</div>
            <div class="unit">г</div>
            <div class="kbju-sub">{{ number_format($dish->total_fat / $w * 100, 1) }} / 100г</div>
        </div>
        <div class="kbju-card">
            <div class="label">Вуглев.</div>
            <div class="value">{{ number_format($dish->total_carb, 1) }}</div>
            <div class="unit">г</div>
            <div class="kbju-sub">{{ number_format($dish->total_carb / $w * 100, 1) }} / 100г</div>
        </div>
        <div class="kbju-card">
            <div class="label">Собівартість</div>
            <div class="value" style="font-size:13px;">{{ number_format($dish->total_cost, 2) }}</div>
            <div class="unit">₴</div>
            <div class="kbju-sub">₴ {{ number_format($dish->total_cost / $w * 100, 2) }} / 100г</div>
        </div>
    </div>

    {{-- ── INGREDIENTS TABLE ── --}}
    <div class="section-title">Склад / Рецептура</div>
    <table>
        <thead>
            <tr>
                <th style="width:32%;text-align:left;">Назва</th>
                <th>Нетто (г)</th>
                <th>Вихід %</th>
                <th>Брутто (г)</th>
                <th>Ккал</th>
                <th>Б (г)</th>
                <th>Ж (г)</th>
                <th>В (г)</th>
                <th>₴</th>
            </tr>
        </thead>
        <tbody>
        @php $rowNum = 0; @endphp
        @foreach($ingredients as $row)
            @if($row['type'] === 'pf')
                <tr class="pf-row">
                    <td>
                        <span class="pf-badge">НФ</span>{{ $row['name'] }}
                        <span style="font-size:8px;color:#0ea5e9;margin-left:4px;">
                            (вхід {{ $row['pf_input'] }}г → вихід {{ $row['pf_output'] }}г)
                        </span>
                    </td>
                    <td>{{ $row['net'] }}</td>
                    <td>{{ $row['yield'] }}%</td>
                    <td>{{ $row['gross'] }}</td>
                    <td>{{ $row['kcal'] }}</td>
                    <td>{{ $row['prot'] }}</td>
                    <td>{{ $row['fat'] }}</td>
                    <td>{{ $row['carb'] }}</td>
                    <td>{{ $row['cost'] }}</td>
                </tr>
            @else
                @php $rowNum++; @endphp
                <tr class="{{ $row['level'] > 0 ? 'sub-row' : '' }}">
                    <td class="{{ $row['level'] > 0 ? 'sub-indent' : '' }}">
                        @if($row['level'] === 0){{ $rowNum }}. @endif{{ $row['name'] }}
                        @if(!empty($row['allergens']))
                            <span style="color:#dc2626;font-size:8px;"> ⚠ {{ $row['allergens'] }}</span>
                        @endif
                    </td>
                    <td>{{ $row['net'] }}</td>
                    <td>{{ $row['yield'] }}%</td>
                    <td>{{ $row['gross'] }}</td>
                    <td>{{ $row['kcal'] }}</td>
                    <td>{{ $row['prot'] }}</td>
                    <td>{{ $row['fat'] }}</td>
                    <td>{{ $row['carb'] }}</td>
                    <td>{{ $row['cost'] }}</td>
                </tr>
            @endif
        @endforeach

        {{-- Totals --}}
        <tr class="total-row">
            <td>ВСЬОГО</td>
            <td>{{ $dish->input_weight }}</td>
            <td>—</td>
            <td>{{ $dish->base_weight_g }}</td>
            <td>{{ number_format($dish->total_kcal, 1) }}</td>
            <td>{{ number_format($dish->total_prot, 1) }}</td>
            <td>{{ number_format($dish->total_fat, 1) }}</td>
            <td>{{ number_format($dish->total_carb, 1) }}</td>
            <td>{{ number_format($dish->total_cost, 2) }}</td>
        </tr>
        <tr class="per100-row">
            <td>НА 100 г</td>
            <td>—</td>
            <td>—</td>
            <td>100</td>
            <td>{{ number_format($dish->total_kcal / $w * 100, 1) }}</td>
            <td>{{ number_format($dish->total_prot / $w * 100, 1) }}</td>
            <td>{{ number_format($dish->total_fat  / $w * 100, 1) }}</td>
            <td>{{ number_format($dish->total_carb / $w * 100, 1) }}</td>
            <td>{{ number_format($dish->total_cost / $w * 100, 2) }}</td>
        </tr>
        </tbody>
    </table>

    {{-- ── НФ КБЖУ BREAKDOWN ── --}}
    @php
        $pfRows = array_filter($ingredients, fn($r) => $r['type'] === 'pf');
    @endphp
    @if(count($pfRows) > 0)
        <div class="section-title" style="margin-top:14px;">КБЖУ Напівфабрикатів (повний розклад)</div>
        <table>
            <thead>
                <tr>
                    <th style="text-align:left;">НФ</th>
                    <th>Вихід (г)</th>
                    <th>Ккал (всього)</th>
                    <th>Б (г)</th>
                    <th>Ж (г)</th>
                    <th>В (г)</th>
                    <th>Ккал/100г</th>
                    <th>Б/100г</th>
                    <th>Ж/100г</th>
                    <th>В/100г</th>
                    <th>₴</th>
                </tr>
            </thead>
            <tbody>
            @foreach($pfRows as $pf)
                @php $pfW = $pf['pf_output'] > 0 ? $pf['pf_output'] : 1; @endphp
                <tr>
                    <td><strong>{{ $pf['name'] }}</strong></td>
                    <td>{{ $pf['pf_output'] }}</td>
                    <td>{{ $pf['pf_kcal'] }}</td>
                    <td>{{ $pf['pf_prot'] }}</td>
                    <td>{{ $pf['pf_fat'] }}</td>
                    <td>{{ $pf['pf_carb'] }}</td>
                    <td>{{ number_format($pf['pf_kcal'] / $pfW * 100, 1) }}</td>
                    <td>{{ number_format($pf['pf_prot'] / $pfW * 100, 1) }}</td>
                    <td>{{ number_format($pf['pf_fat']  / $pfW * 100, 1) }}</td>
                    <td>{{ number_format($pf['pf_carb'] / $pfW * 100, 1) }}</td>
                    <td>{{ number_format($pf['pf_cost'], 2) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    {{-- ── RECIPE ── --}}
    @if(!empty($dish->description))
        <div class="section-title" style="margin-top:14px;">Рецепт приготування</div>
        <div class="recipe-box">
            {!! $dish->description !!}
        </div>
    @endif

    {{-- ── FOOTER ── --}}
    <div class="tc-footer">
        <span>Страва: {{ $dish->name }} · ID {{ $dish->id }}</span>
        <span>Дата: {{ \Carbon\Carbon::now()->format('d.m.Y') }}</span>
    </div>

</div>
</body>
</html>
