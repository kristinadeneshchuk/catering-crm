<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>Циклічне меню</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        body { font-family: Arial, Helvetica, sans-serif; font-size:12px; background:#f1f5f9; color:#0f172a; }

        .no-print { padding:20px; text-align:center; }
        .no-print button {
            background:#1e293b; color:white; border:none;
            padding:14px 36px; border-radius:10px; font-size:15px;
            font-weight:900; cursor:pointer; letter-spacing:1.5px; text-transform:uppercase;
        }
        .meal-filters {
            display:flex; flex-wrap:wrap; gap:10px; justify-content:center;
            margin-top:14px;
        }
        .meal-filters label {
            display:flex; align-items:center; gap:6px;
            background:#f1f5f9; border:1.5px solid #e2e8f0; border-radius:8px;
            padding:6px 14px; cursor:pointer; font-size:13px; font-weight:600;
            user-select:none; transition:background 0.15s, border-color 0.15s;
        }
        .meal-filters label:hover { background:#e2e8f0; }
        .meal-filters input[type=checkbox] { width:15px; height:15px; cursor:pointer; accent-color:#1e293b; }
        .filter-hint { font-size:11px; color:#94a3b8; margin-top:8px; }

        .page { width:210mm; margin:16px auto; background:white; padding:12mm 14mm; }

        .doc-header { border-bottom:3px solid #0f172a; padding-bottom:5mm; margin-bottom:8mm; display:flex; justify-content:space-between; align-items:flex-end; }
        .doc-header h1 { font-size:22px; font-weight:900; text-transform:uppercase; }
        .doc-header p { font-size:11px; color:#64748b; margin-top:3px; }
        .doc-header-right { text-align:right; font-size:11px; color:#64748b; }

        .day-block { margin-bottom:8mm; break-inside:avoid; }
        .day-title {
            background:#0f172a; color:white;
            font-size:13px; font-weight:900; text-transform:uppercase;
            letter-spacing:0.8px;
            padding:5px 12px;
            border-radius:6px;
            margin-bottom:3mm;
            display:inline-block;
        }

        table { width:100%; border-collapse:collapse; }
        thead th {
            font-size:9px; color:#64748b; text-transform:uppercase; font-weight:700;
            padding:4px 8px; border-bottom:1.5px solid #e2e8f0; text-align:left;
        }
        tbody tr:nth-child(even) td { background:#f8fafc; }
        tbody td { padding:6px 8px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        .meal-name { font-weight:700; font-size:11px; color:#475569; white-space:nowrap; }
        .dish-name { font-size:12px; font-weight:600; }
        .num { text-align:right; font-variant-numeric: tabular-nums; white-space:nowrap; font-size:11px; }
        .num-kcal { color:#0f172a; font-weight:700; }
        .num-prot { color:#1d4ed8; }
        .num-fat  { color:#b45309; }
        .num-carb { color:#15803d; }
        tbody tr.totals-row td { background:#0f172a !important; color:white; font-weight:800; border-bottom:none; }
        tbody tr.totals-row td.num { color:white; }
        thead th.num { text-align:right; }

        @media print {
            .no-print { display:none; }
            body { background:white; }
            .page { margin:0; padding:10mm 12mm; width:100%; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button onclick="window.print()">🖨 Друкувати</button>

    <div class="meal-filters" id="mealFilters"></div>
    <div class="filter-hint">Оберіть прийоми їжі для друку</div>
</div>

<div class="page">
    <div class="doc-header">
        <div>
            <h1>Циклічне меню</h1>
            <p>Повний список страв по днях циклу</p>
        </div>
        <div class="doc-header-right">
            Всього днів: <strong>{{ $menus->count() }}</strong>
        </div>
    </div>

    @forelse($menus as $menu)
        <div class="day-block">
            <div class="day-title">День {{ $menu->day_number }}</div>

            @php
                $targetKcal = 1800;

                $items = $menu->menuItems
                    ->filter(fn($i) => $i->dish)
                    ->sortBy(fn($i) => $i->mealType?->sort_order ?? 99)
                    ->values();

                $byMeal = $items->groupBy('meal_type_id');

                $rawPct = [];
                foreach ($byMeal as $mealTypeId => $arr) {
                    $fi = $arr->first();
                    $rawPct[$mealTypeId] = $fi->custom_energy_percent !== null
                        ? (float) $fi->custom_energy_percent
                        : (float) ($fi->mealType?->energy_percent ?? 0);
                }
                $totalPct   = array_sum($rawPct);
                $normFactor = ($totalPct > 0.5 && abs($totalPct - 100) > 0.5) ? (100.0 / $totalPct) : 1.0;

                $rows = [];
                $dayTotals = ['kcal' => 0.0, 'prot' => 0.0, 'fat' => 0.0, 'carb' => 0.0];

                foreach ($byMeal as $mealTypeId => $arr) {
                    $first    = $arr->first();
                    $mealName = $first->mealType?->name ?? 'Інше';
                    $mealSort = $first->mealType?->sort_order ?? 99;

                    $p = ($rawPct[$mealTypeId] ?? 0) * $normFactor;
                    $mealKcal = $p > 0
                        ? $targetKcal * ($p / 100.0)
                        : $targetKcal * (1.0 / max(1, $byMeal->count()));
                    $kcalPerDish = $mealKcal / max(1, $arr->count());

                    foreach ($arr as $mi) {
                        $d = $mi->dish;
                        $baseW     = (float) ($d->base_weight_g ?? 0);
                        $totalKcal = (float) ($d->total_kcal ?? 0);
                        $kcal100   = ($baseW > 0 && $totalKcal > 0) ? ($totalKcal / $baseW) * 100.0 : 0;
                        $weight    = $kcal100 > 0 ? (int) round(($kcalPerDish / $kcal100) * 100.0) : 0;

                        $protPerG = $baseW > 0 ? (float) ($d->total_prot ?? 0) / $baseW : 0.0;
                        $fatPerG  = $baseW > 0 ? (float) ($d->total_fat  ?? 0) / $baseW : 0.0;
                        $carbPerG = $baseW > 0 ? (float) ($d->total_carb ?? 0) / $baseW : 0.0;

                        $dishKcal = $weight * $kcal100 / 100.0;
                        $dishProt = $weight * $protPerG;
                        $dishFat  = $weight * $fatPerG;
                        $dishCarb = $weight * $carbPerG;

                        $dayTotals['kcal'] += $dishKcal;
                        $dayTotals['prot'] += $dishProt;
                        $dayTotals['fat']  += $dishFat;
                        $dayTotals['carb'] += $dishCarb;

                        $rows[] = [
                            'meal_name' => $mealName,
                            'meal_sort' => $mealSort,
                            'dish_name' => $d->name,
                            'weight'    => $weight,
                            'kcal'      => $dishKcal,
                            'prot'      => $dishProt,
                            'fat'       => $dishFat,
                            'carb'      => $dishCarb,
                        ];
                    }
                }

                usort($rows, fn($a, $b) => $a['meal_sort'] <=> $b['meal_sort']);
            @endphp

            <table>
                <thead>
                    <tr>
                        <th style="width:140px;">Прийом їжі</th>
                        <th>Страва</th>
                        <th class="num" style="width:50px;">Вага</th>
                        <th class="num" style="width:60px;">Ккал</th>
                        <th class="num" style="width:48px;">Б, г</th>
                        <th class="num" style="width:48px;">Ж, г</th>
                        <th class="num" style="width:48px;">В, г</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                        <tr data-meal="{{ $r['meal_name'] }}">
                            <td class="meal-name">{{ $r['meal_name'] }}</td>
                            <td class="dish-name">{{ $r['dish_name'] }}</td>
                            <td class="num">{{ $r['weight'] }} г</td>
                            <td class="num num-kcal">{{ number_format($r['kcal'], 0, '.', '') }}</td>
                            <td class="num num-prot">{{ number_format($r['prot'], 1, '.', '') }}</td>
                            <td class="num num-fat">{{ number_format($r['fat'],  1, '.', '') }}</td>
                            <td class="num num-carb">{{ number_format($r['carb'], 1, '.', '') }}</td>
                        </tr>
                    @endforeach
                    <tr class="totals-row">
                        <td colspan="3">Разом за день (норма {{ $targetKcal }} ккал)</td>
                        <td class="num">{{ number_format($dayTotals['kcal'], 0, '.', '') }}</td>
                        <td class="num">{{ number_format($dayTotals['prot'], 1, '.', '') }}</td>
                        <td class="num">{{ number_format($dayTotals['fat'],  1, '.', '') }}</td>
                        <td class="num">{{ number_format($dayTotals['carb'], 1, '.', '') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @empty
        <p style="color:#64748b; text-align:center; padding:20mm 0;">Меню ще не заповнено</p>
    @endforelse
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Збираємо всі унікальні прийоми їжі з DOM
        const rows = document.querySelectorAll('tr[data-meal]');
        const meals = [...new Set([...rows].map(r => r.dataset.meal))];

        const container = document.getElementById('mealFilters');

        meals.forEach(meal => {
            const label = document.createElement('label');
            const cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = true;
            cb.value = meal;
            cb.addEventListener('change', applyFilters);
            label.appendChild(cb);
            label.appendChild(document.createTextNode(meal));
            container.appendChild(label);
        });

        function applyFilters() {
            const checked = [...container.querySelectorAll('input:checked')].map(c => c.value);
            rows.forEach(row => {
                row.style.display = checked.includes(row.dataset.meal) ? '' : 'none';
            });
        }
    });
</script>
</body>
</html>
