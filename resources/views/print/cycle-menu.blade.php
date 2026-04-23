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
                $grouped = $menu->menuItems->sortBy(fn($i) => $i->mealType?->sort_order ?? 99)->groupBy(fn($i) => $i->mealType?->name ?? 'Інше');
            @endphp

            <table>
                <thead>
                    <tr>
                        <th style="width:140px;">Прийом їжі</th>
                        <th>Страва</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($grouped as $mealName => $items)
                        @foreach($items as $item)
                            <tr data-meal="{{ $mealName }}">
                                <td class="meal-name">{{ $mealName }}</td>
                                <td class="dish-name">{{ $item->dish?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    @endforeach
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
