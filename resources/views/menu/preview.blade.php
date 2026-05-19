<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Наше меню — приклад на 3 дні</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f4f8;
            color: #1a202c;
            min-height: 100vh;
            padding-bottom: 40px;
        }

        .wrap {
            max-width: 720px;
            margin: 0 auto;
            padding: 0 16px;
        }

        .hero {
            background: #1a202c;
            color: white;
            padding: 28px 20px 24px;
            text-align: center;
        }
        .hero h1 {
            font-size: 22px;
            font-weight: 800;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        .hero p {
            font-size: 13px;
            opacity: 0.7;
            line-height: 1.5;
        }

        .info-card {
            background: white;
            border-radius: 14px;
            padding: 18px 18px 16px;
            margin-top: -16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border: 1px solid #e2e8f0;
        }
        .info-card h2 {
            font-size: 13px;
            font-weight: 800;
            color: #1a202c;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }
        .info-row {
            display: flex;
            align-items: baseline;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px dashed #edf2f7;
            font-size: 14px;
        }
        .info-row:last-child { border-bottom: none; }
        .info-kcal {
            font-weight: 700;
            color: #1a202c;
            min-width: 130px;
        }
        .info-meals {
            color: #4a5568;
            flex: 1;
        }
        .info-count {
            font-weight: 700;
            color: #38a169;
        }

        .selector-card {
            background: white;
            border-radius: 14px;
            padding: 18px;
            margin-top: 16px;
            border: 1px solid #e2e8f0;
        }
        .selector-card h2 {
            font-size: 13px;
            font-weight: 800;
            color: #1a202c;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }
        .kcal-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 8px;
        }
        .kcal-btn {
            display: block;
            padding: 12px 10px;
            border-radius: 10px;
            background: #f7fafc;
            border: 2px solid transparent;
            text-align: center;
            text-decoration: none;
            color: #2d3748;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.15s;
            cursor: pointer;
        }
        .kcal-btn:hover {
            background: #edf2f7;
        }
        .kcal-btn.active {
            background: #1a202c;
            color: white;
            border-color: #1a202c;
        }

        .day-card {
            background: white;
            border-radius: 14px;
            margin-top: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
        }
        .day-header {
            background: linear-gradient(135deg, #1a202c, #2d3748);
            color: white;
            padding: 14px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .day-header-title {
            font-size: 15px;
            font-weight: 800;
        }
        .day-header-date {
            font-size: 12px;
            opacity: 0.7;
        }

        .meal {
            padding: 14px 18px;
            border-bottom: 1px solid #edf2f7;
        }
        .meal:last-child { border-bottom: none; }
        .meal-label {
            font-size: 11px;
            font-weight: 700;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 4px;
        }
        .meal-dish {
            font-size: 15px;
            font-weight: 600;
            color: #1a202c;
            margin-bottom: 4px;
            line-height: 1.3;
        }
        .meal-meta {
            font-size: 12px;
            color: #718096;
            display: flex;
            gap: 12px;
        }
        .meal-meta b {
            color: #38a169;
            font-weight: 700;
        }

        .empty {
            padding: 30px 20px;
            text-align: center;
            color: #a0aec0;
            font-size: 13px;
        }

        .footer-note {
            margin-top: 18px;
            padding: 14px 18px;
            background: #fef5e7;
            border: 1px solid #fcd9a0;
            border-radius: 12px;
            font-size: 12px;
            color: #7b5e1e;
            line-height: 1.5;
            text-align: center;
        }

        @media (max-width: 480px) {
            .hero h1 { font-size: 19px; }
            .meal-dish { font-size: 14px; }
        }
    </style>
</head>
<body>

    <header class="hero">
        <h1>Меню на найближчі 3 дні</h1>
        <p>Приклад того, що отримують наші клієнти</p>
    </header>

    <div class="wrap">

        @if ($mealPlanInfo->isNotEmpty())
            <section class="info-card">
                <h2>Скільки прийомів на ваш калораж</h2>
                @foreach ($mealPlanInfo as $row)
                    <div class="info-row">
                        <span class="info-kcal">{{ $row['range_label'] }}</span>
                        <span class="info-meals">
                            <span class="info-count">{{ $row['meal_count'] }}</span>
                            {{ $row['meal_count'] === 1 ? 'прийом' : ($row['meal_count'] < 5 ? 'прийоми' : 'прийомів') }}:
                            {{ implode(', ', $row['meal_names']) }}
                        </span>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($calorieOptions->isNotEmpty())
            <section class="selector-card">
                <h2>Оберіть свій калораж</h2>
                <div class="kcal-grid">
                    @foreach ($calorieOptions as $opt)
                        <a href="?kcal={{ $opt['value'] }}"
                           class="kcal-btn {{ $kcal === (int)$opt['value'] ? 'active' : '' }}">
                            {{ $opt['label'] }}
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        @foreach ($days as $i => $day)
            <section class="day-card">
                <div class="day-header">
                    <span class="day-header-title">
                        @if ($i === 0) Сьогодні
                        @elseif ($i === 1) Завтра
                        @else Післязавтра
                        @endif
                        · {{ $day['weekday'] }}
                    </span>
                    <span class="day-header-date">{{ $day['date']->format('d.m.Y') }}</span>
                </div>

                @if (empty($day['items']))
                    <div class="empty">Меню на цей день поки не сформовано</div>
                @else
                    @foreach ($day['items'] as $item)
                        <div class="meal">
                            <div class="meal-label">{{ $item['meal'] }}</div>
                            <div class="meal-dish">{{ $item['dish_name'] }}</div>
                            <div class="meal-meta">
                                <span><b>{{ $item['weight'] }}</b> г</span>
                                <span><b>{{ $item['kcal'] }}</b> ккал</span>
                            </div>
                        </div>
                    @endforeach
                @endif
            </section>
        @endforeach

        <div class="footer-note">
            Це приклад меню. Реальний раціон формується з урахуванням ваших побажань та виключень.
        </div>

    </div>

</body>
</html>
