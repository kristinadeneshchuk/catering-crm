<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Меню на сьогодні</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
        body { font-family: Arial, Helvetica, sans-serif; font-size:14px; background:#f1f5f9; color:#0f172a; padding-bottom:40px; }

        .wrap { max-width:760px; margin:0 auto; padding:16px; }

        .doc-header { border-bottom:3px solid #0f172a; padding-bottom:14px; margin-bottom:16px; }
        .doc-header h1 { font-size:24px; font-weight:900; text-transform:uppercase; letter-spacing:0.5px; }
        .doc-header p { font-size:13px; color:#64748b; margin-top:4px; }

        .tiers-label { font-size:12px; color:#64748b; text-transform:uppercase; letter-spacing:1px; font-weight:700; margin-bottom:8px; }
        .tiers { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:22px; }
        .tier {
            display:inline-block; text-decoration:none;
            background:#fff; border:1.5px solid #e2e8f0; border-radius:10px;
            padding:10px 16px; font-size:15px; font-weight:800; color:#334155;
            transition:all .12s;
        }
        .tier:hover { border-color:#94a3b8; }
        .tier.active { background:#0f172a; border-color:#0f172a; color:#fff; }
        .tier small { display:block; font-size:9px; font-weight:600; opacity:.7; text-transform:uppercase; letter-spacing:.5px; }

        .day-block { background:#fff; border-radius:14px; padding:16px; margin-bottom:16px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
        .day-title {
            display:inline-block; background:#0f172a; color:#fff;
            font-size:14px; font-weight:900; text-transform:uppercase; letter-spacing:.8px;
            padding:6px 14px; border-radius:8px; margin-bottom:12px;
        }
        .day-title span { font-weight:600; opacity:.75; text-transform:none; letter-spacing:0; margin-left:6px; font-size:12px; }

        table { width:100%; border-collapse:collapse; }
        thead th {
            font-size:10px; color:#64748b; text-transform:uppercase; font-weight:700;
            padding:4px 6px; border-bottom:1.5px solid #e2e8f0; text-align:right; white-space:nowrap;
        }
        thead th.l { text-align:left; }
        tbody td { padding:8px 6px; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
        tbody tr:last-child td { border-bottom:none; }
        .meal-name { font-weight:700; font-size:11px; color:#64748b; white-space:nowrap; }
        .dish-name { font-size:14px; font-weight:600; }
        .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; font-size:13px; }
        .num-w    { font-weight:700; }
        .num-kcal { color:#0f172a; font-weight:800; }
        .num-prot { color:#1d4ed8; }
        .num-fat  { color:#b45309; }
        .num-carb { color:#15803d; }

        .day-total td { border-top:2px solid #0f172a; border-bottom:none; padding-top:10px; font-weight:900; }
        .day-total .lbl { text-transform:uppercase; font-size:11px; letter-spacing:.5px; }

        .empty { color:#94a3b8; font-size:13px; padding:14px 6px; }

        @media (max-width:520px) {
            .dish-name { font-size:13px; }
            .num { font-size:12px; }
            thead th { font-size:9px; }
            /* ховаємо Б/Ж/В-заголовки словами, лишаємо літери */

            /* Вузький телефон: 7 колонок не вміщались і «В» (вуглеводи)
               обрізалась об край картки. Тиснемо падінги і колонку прийому. */
            .wrap { padding:10px; }
            .day-block { padding:12px 10px; }
            thead th, tbody td { padding-left:3px; padding-right:3px; }
            th.l:first-child, td:first-child { font-size:11px; }
        }

        /* Довгі назви страв можуть зробити таблицю ширшою за екран телефона.
           Даємо їй горизонтальний скрол усередині картки дня — усі колонки
           КБЖУ лишаються на місці, нічого не ріжеться. */
        .table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="doc-header">
        <h1>Меню на 3 дні</h1>
        <p>Оберіть свій калораж — граммовка та КБЖУ підрахуються автоматично</p>
    </div>

    <div class="tiers-label">Калораж</div>
    <div class="tiers">
        @foreach ($tiers as $t)
            <a class="tier {{ $t === $kcal ? 'active' : '' }}" href="{{ route('menu.today', ['kcal' => $t]) }}">
                {{ $t }}<small>ккал</small>
            </a>
        @endforeach
    </div>

    @forelse ($days as $day)
        <div class="day-block">
            <div class="day-title">
                {{ $day['label'] }}<span>{{ $day['date']->format('d.m') }}</span>
            </div>

            @if (count($day['items']))
                <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th class="l">Прийом</th>
                            <th class="l">Страва</th>
                            <th>Вага</th>
                            <th>Ккал</th>
                            <th>Б</th>
                            <th>Ж</th>
                            <th>В</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($day['items'] as $it)
                            <tr>
                                <td class="meal-name">{{ $it['meal'] }}</td>
                                <td class="dish-name">{{ $it['dish_name'] }}</td>
                                <td class="num num-w">{{ $it['weight'] }} г</td>
                                <td class="num num-kcal">{{ round($it['kcal']) }}</td>
                                <td class="num num-prot">{{ round($it['prot'], 1) }}</td>
                                <td class="num num-fat">{{ round($it['fat'], 1) }}</td>
                                <td class="num num-carb">{{ round($it['carb'], 1) }}</td>
                            </tr>
                        @endforeach
                        <tr class="day-total">
                            <td class="lbl" colspan="2">Разом за день</td>
                            <td class="num"></td>
                            <td class="num num-kcal">{{ round($day['totals']['kcal']) }}</td>
                            <td class="num num-prot">{{ round($day['totals']['prot'], 1) }}</td>
                            <td class="num num-fat">{{ round($day['totals']['fat'], 1) }}</td>
                            <td class="num num-carb">{{ round($day['totals']['carb'], 1) }}</td>
                        </tr>
                    </tbody>
                </table>
                </div>
            @else
                <div class="empty">Меню на цей день ще не сформоване.</div>
            @endif
        </div>
    @empty
        <div class="day-block"><div class="empty">Меню тимчасово недоступне.</div></div>
    @endforelse
</div>
</body>
</html>
