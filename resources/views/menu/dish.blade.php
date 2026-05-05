<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>{{ $dish->name }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f4f8;
            color: #1a202c;
            min-height: 100vh;
        }

        /* ── Header ── */
        .header {
            background: #1a202c;
            color: white;
            padding: 14px 20px 14px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
            font-size: 18px;
            flex-shrink: 0;
        }
        .header-info { flex: 1; }
        .header-meal {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            opacity: 0.5;
            margin-bottom: 2px;
        }
        .header-dish {
            font-size: 16px;
            font-weight: 800;
            line-height: 1.2;
        }

        /* ── Content ── */
        .content {
            padding: 16px;
            max-width: 480px;
            margin: 0 auto;
        }

        /* ── KBJU card ── */
        .kbju-card {
            background: white;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .kbju-card-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #a0aec0;
            margin-bottom: 14px;
        }

        .kbju-main {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .kcal-big {
            text-align: center;
        }
        .kcal-big-value {
            font-size: 42px;
            font-weight: 900;
            color: #e53e3e;
            line-height: 1;
        }
        .kcal-big-label {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #fc8181;
            margin-top: 2px;
        }

        .kbju-divider-v {
            width: 1px;
            height: 50px;
            background: #e2e8f0;
        }

        .kbju-macros {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .macro-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .macro-label {
            font-size: 12px;
            color: #718096;
            font-weight: 500;
        }
        .macro-value {
            font-size: 14px;
            font-weight: 800;
            color: #2d3748;
        }
        .macro-value.prot { color: #3182ce; }
        .macro-value.fat  { color: #d69e2e; }
        .macro-value.carb { color: #38a169; }

        /* Progress bar */
        .daily-progress {
            border-top: 1px solid #f0f4f8;
            padding-top: 14px;
        }
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .progress-label {
            font-size: 11px;
            color: #718096;
        }
        .progress-pct {
            font-size: 12px;
            font-weight: 800;
            color: #4a5568;
        }
        .progress-bar-bg {
            height: 6px;
            background: #e2e8f0;
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background: #e53e3e;
            border-radius: 3px;
            transition: width 0.6s ease;
        }

        /* Weight badge */
        .weight-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f0faf4;
            border: 1px solid #9ae6b4;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 14px;
            font-weight: 800;
            color: #276749;
            margin-top: 12px;
        }

        /* ── Ingredients ── */
        .ingredients-card {
            background: white;
            border-radius: 14px;
            padding: 18px;
            margin-bottom: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .card-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #a0aec0;
            margin-bottom: 14px;
        }

        .ingredient-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f7fafc;
        }
        .ingredient-row:last-child { border-bottom: none; }

        .ingredient-name {
            font-size: 14px;
            font-weight: 500;
            color: #2d3748;
            flex: 1;
        }
        .ingredient-name.replaced {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .ingredient-original {
            font-size: 11px;
            color: #a0aec0;
            text-decoration: line-through;
        }
        .ingredient-new {
            font-size: 14px;
            font-weight: 700;
            color: #2d8a4e;
        }
        .replaced-badge {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #38a169;
            background: #f0faf4;
            border-radius: 4px;
            padding: 1px 5px;
            margin-left: 4px;
        }

        .ingredient-weight {
            font-size: 13px;
            font-weight: 700;
            color: #718096;
            white-space: nowrap;
            margin-left: 12px;
        }

        /* ── Allergens ── */
        .allergens-card {
            background: #fffbeb;
            border: 1px solid #fbd38d;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 12px;
        }
        .allergens-header {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 10px;
        }
        .allergens-icon { font-size: 16px; }
        .allergens-title {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #b7791f;
        }
        .allergen-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .allergen-tag {
            background: white;
            border: 1px solid #fbd38d;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 600;
            color: #744210;
        }
    </style>
</head>
<body>

<div class="header">
    <a href="{{ route('menu.show', $token) }}?date={{ $date->format('Y-m-d') }}" class="back-btn">←</a>
    <div class="header-info">
        <div class="header-meal">{{ $meal }}</div>
        <div class="header-dish">{{ $dish->name }}</div>
    </div>
</div>

<div class="content">

    {{-- КБЖУ та вага --}}
    <div class="kbju-card">
        <div class="kbju-card-title">Поживна цінність</div>

        <div class="kbju-main">
            <div class="kcal-big">
                <div class="kcal-big-value">{{ $kcal }}</div>
                <div class="kcal-big-label">ккал</div>
            </div>
            <div class="kbju-divider-v"></div>
            <div class="kbju-macros">
                <div class="macro-row">
                    <span class="macro-label">Білки</span>
                    <span class="macro-value prot">{{ $prot }} г</span>
                </div>
                <div class="macro-row">
                    <span class="macro-label">Жири</span>
                    <span class="macro-value fat">{{ $fat }} г</span>
                </div>
                <div class="macro-row">
                    <span class="macro-label">Вуглеводи</span>
                    <span class="macro-value carb">{{ $carb }} г</span>
                </div>
            </div>
        </div>

        {{-- % від денної норми --}}
        @if($pct_of_daily > 0)
            <div class="daily-progress">
                <div class="progress-header">
                    <span class="progress-label">Від вашої денної норми</span>
                    <span class="progress-pct">{{ $pct_of_daily }}%</span>
                </div>
                <div class="progress-bar-bg">
                    <div class="progress-bar-fill" style="width: {{ min($pct_of_daily, 100) }}%"></div>
                </div>
            </div>
        @endif

        <div class="weight-badge">
            ⚖ Порція: {{ $weight }} г
        </div>
    </div>

    {{-- Алергени --}}
    @if(!empty($allergens))
        <div class="allergens-card">
            <div class="allergens-header">
                <span class="allergens-icon">⚠️</span>
                <span class="allergens-title">Алергени</span>
            </div>
            <div class="allergen-tags">
                @foreach($allergens as $allergen)
                    <span class="allergen-tag">{{ $allergen }}</span>
                @endforeach
            </div>
        </div>
    @endif

</div>

</body>
</html>
