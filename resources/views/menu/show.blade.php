<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Моє меню — {{ $date->format('d.m.Y') }}</title>
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
            padding: 16px 20px 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .header-brand {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: 0.5;
            margin-bottom: 2px;
        }
        .header-name {
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
        }
        .header-tariff {
            font-size: 12px;
            opacity: 0.6;
            margin-top: 2px;
        }

        /* ── Date navigation ── */
        .date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .date-nav-btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            background: #f0f4f8;
            color: #4a5568;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .date-nav-btn:hover { background: #e2e8f0; }
        .date-nav-btn.disabled {
            opacity: 0.3;
            pointer-events: none;
        }
        .date-center {
            text-align: center;
        }
        .date-main {
            font-size: 16px;
            font-weight: 800;
            color: #1a202c;
        }
        .date-sub {
            font-size: 11px;
            color: #718096;
            margin-top: 1px;
        }

        /* ── Content ── */
        .content {
            padding: 16px;
            max-width: 480px;
            margin: 0 auto;
        }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #718096;
        }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 18px; font-weight: 700; color: #4a5568; margin-bottom: 8px; }
        .empty-state-text { font-size: 14px; line-height: 1.5; }

        /* ── Dish card ── */
        .meal-section { margin-bottom: 8px; }
        .meal-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #718096;
            padding: 0 4px;
            margin-bottom: 6px;
        }

        .dish-card {
            background: white;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 8px;
            text-decoration: none;
            color: inherit;
            display: block;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            transition: transform 0.1s, box-shadow 0.1s;
        }
        .dish-card:active {
            transform: scale(0.98);
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        }

        .dish-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .dish-name {
            font-size: 15px;
            font-weight: 700;
            line-height: 1.3;
            flex: 1;
        }
        .dish-weight {
            font-size: 14px;
            font-weight: 800;
            color: #2d8a4e;
            white-space: nowrap;
            background: #f0faf4;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .dish-kbju {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .kbju-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #f7fafc;
            border-radius: 8px;
            padding: 5px 10px;
            min-width: 52px;
        }
        .kbju-value {
            font-size: 14px;
            font-weight: 800;
            color: #2d3748;
        }
        .kbju-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #a0aec0;
            margin-top: 1px;
        }
        .kbju-item.kcal .kbju-value { color: #e53e3e; }
        .kbju-item.prot .kbju-value { color: #3182ce; }
        .kbju-item.fat  .kbju-value { color: #d69e2e; }
        .kbju-item.carb .kbju-value { color: #38a169; }

        /* Заміна інгредієнта */
        .dish-change-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 700;
            color: #c53030;
            margin-top: 8px;
        }

        .dish-arrow {
            font-size: 12px;
            color: #a0aec0;
            margin-top: 8px;
            text-align: right;
        }

        /* ── Daily totals ── */
        .totals-card {
            background: #1a202c;
            border-radius: 14px;
            padding: 18px;
            margin-top: 8px;
            margin-bottom: 24px;
        }
        .totals-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.4);
            margin-bottom: 12px;
        }
        .totals-row {
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }
        .total-item {
            flex: 1;
            text-align: center;
        }
        .total-value {
            font-size: 20px;
            font-weight: 900;
            color: white;
            line-height: 1;
        }
        .total-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.4);
            margin-top: 4px;
        }
        .total-item.kcal .total-value { color: #fc8181; }
        .total-item.prot .total-value { color: #63b3ed; }
        .total-item.fat  .total-value { color: #f6e05e; }
        .total-item.carb .total-value { color: #68d391; }

        .total-divider {
            width: 1px;
            background: rgba(255,255,255,0.08);
        }
    </style>
</head>
<body>

<div class="header">
    <div class="header-brand">
        {{ $order->projectData?->name ?? 'AVOCADO' }} · Меню
    </div>
    <div class="header-name">{{ $client->name }}</div>
    @if($order->tariff)
        <div class="header-tariff">{{ $order->tariff->name }} · {{ $order->calories }} ккал</div>
    @endif
</div>

<div class="date-nav">
    @if($hasPrev)
        <a href="{{ route('menu.show', $token) }}?date={{ $prevDate->format('Y-m-d') }}" class="date-nav-btn">
            ← {{ $prevDate->format('d.m') }}
        </a>
    @else
        <span class="date-nav-btn disabled">← —</span>
    @endif

    <div class="date-center">
        <div class="date-main">
            @if($date->isToday())
                Сьогодні, {{ $date->isoFormat('D MMMM') }}
            @elseif($date->isYesterday())
                Вчора, {{ $date->isoFormat('D MMMM') }}
            @elseif($date->isTomorrow())
                Завтра, {{ $date->isoFormat('D MMMM') }}
            @else
                {{ $date->isoFormat('D MMMM') }}
            @endif
        </div>
        <div class="date-sub">{{ $date->isoFormat('dddd') }}</div>
    </div>

    @if($hasNext)
        <a href="{{ route('menu.show', $token) }}?date={{ $nextDate->format('Y-m-d') }}" class="date-nav-btn">
            {{ $nextDate->format('d.m') }} →
        </a>
    @else
        <span class="date-nav-btn disabled">— →</span>
    @endif
</div>

<div class="content">

    @if(empty($items))
        <div class="empty-state">
            <div class="empty-state-icon">🍽</div>
            <div class="empty-state-title">Меню на цей день відсутнє</div>
            <div class="empty-state-text">Цей день не входить у ваше замовлення або меню ще не сформоване.</div>
        </div>
    @else

        @php
            $groupedItems = collect($items)->groupBy('meal');
        @endphp

        @foreach($groupedItems as $mealName => $dishes)
            <div class="meal-section">
                <div class="meal-label">{{ $mealName }}</div>

                @foreach($dishes as $item)
                    @php
                        // Перевіряємо чи є заміни для цієї страви
                        $hasChanges = $order->replacements->where('dish_id', $item['dish_id'])->isNotEmpty();
                    @endphp
                    <a href="{{ route('menu.dish', [$token, $item['dish_id']]) }}?date={{ $date->format('Y-m-d') }}"
                       class="dish-card">
                        <div class="dish-card-top">
                            <div class="dish-name">{{ $item['dish_name'] }}</div>
                            <div class="dish-weight">{{ $item['weight'] }}г</div>
                        </div>

                        <div class="dish-kbju">
                            <div class="kbju-item kcal">
                                <span class="kbju-value">{{ round($item['kcal']) }}</span>
                                <span class="kbju-label">ккал</span>
                            </div>
                            <div class="kbju-item prot">
                                <span class="kbju-value">{{ round($item['prot'], 1) }}</span>
                                <span class="kbju-label">білки</span>
                            </div>
                            <div class="kbju-item fat">
                                <span class="kbju-value">{{ round($item['fat'], 1) }}</span>
                                <span class="kbju-label">жири</span>
                            </div>
                            <div class="kbju-item carb">
                                <span class="kbju-value">{{ round($item['carb'], 1) }}</span>
                                <span class="kbju-label">вуглев.</span>
                            </div>
                        </div>

                        @if($hasChanges)
                            <div class="dish-change-badge">
                                ⚡ Індивідуальний склад
                            </div>
                        @endif

                        <div class="dish-arrow">Склад →</div>
                    </a>
                @endforeach
            </div>
        @endforeach

        {{-- Підсумок дня --}}
        <div class="totals-card">
            <div class="totals-title">Разом за день</div>
            <div class="totals-row">
                <div class="total-item kcal">
                    <div class="total-value">{{ round($totals['kcal']) }}</div>
                    <div class="total-label">ккал</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item prot">
                    <div class="total-value">{{ round($totals['prot'], 1) }}</div>
                    <div class="total-label">білки г</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item fat">
                    <div class="total-value">{{ round($totals['fat'], 1) }}</div>
                    <div class="total-label">жири г</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item carb">
                    <div class="total-value">{{ round($totals['carb'], 1) }}</div>
                    <div class="total-label">вуглев. г</div>
                </div>
            </div>
        </div>

    @endif
</div>

</body>
</html>
