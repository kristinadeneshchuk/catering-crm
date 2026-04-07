<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Меню кухні — {{ $date }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f1f5f9; color: #1e293b; }

        .meal-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }
        .meal-snidanok  { background: #0d9488; color: #fff; }
        .meal-perekus1  { background: #84cc16; color: #1a2e05; }
        .meal-obid      { background: #f97316; color: #fff; }
        .meal-perekus2  { background: #ec4899; color: #fff; }
        .meal-vecherya  { background: #38bdf8; color: #0c4a6e; }
        .meal-default   { background: #94a3b8; color: #fff; }

        .dish-card {
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            transition: box-shadow 0.2s, transform 0.2s;
        }
        .dish-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.10); transform: translateY(-1px); }

        .recipe-content h2 { font-size: 1.05em; font-weight: 700; color: #1e293b; margin: 12px 0 4px; }
        .recipe-content h3 { font-size: 1em; font-weight: 600; color: #334155; margin: 10px 0 4px; }
        .recipe-content p  { color: #1e293b; line-height: 1.7; margin: 6px 0; }
        .recipe-content ul, .recipe-content ol { padding-left: 20px; color: #1e293b; }
        .recipe-content li { margin: 4px 0; line-height: 1.6; }
        .recipe-content strong { color: #0f172a; }
        .recipe-content em { color: #4f46e5; }
        .recipe-content blockquote {
            border-left: 3px solid #f97316;
            padding-left: 12px;
            color: #92400e;
            font-style: italic;
            margin: 10px 0;
            background: #fff7ed;
            border-radius: 0 6px 6px 0;
        }

        @media print {
            body { background: #fff; }
            .dish-card { box-shadow: none; border: 1px solid #ccc; break-inside: avoid; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

    {{-- HEADER --}}
    <div style="background: #ffffff; border-bottom: 1px solid #e2e8f0; padding: 14px 20px; position: sticky; top: 0; z-index: 50; box-shadow: 0 1px 4px rgba(0,0,0,0.06);">
        <div style="max-width: 900px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div>
                <div style="font-size: 20px; font-weight: 800; color: #0f172a;">Меню кухні</div>
                <div style="font-size: 13px; color: #64748b; margin-top: 2px;">
                    {{ $date }} &nbsp;·&nbsp; День циклу #{{ $globalDay }}
                </div>
            </div>
            <div style="display: flex; gap: 8px; align-items: center;" class="no-print">
                <a href="?date={{ \Carbon\Carbon::parse($rawDate)->subDay()->format('Y-m-d') }}"
                   style="background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; border-radius:8px; padding:6px 14px; text-decoration:none; font-size:13px; font-weight:500;">
                    ← Вчора
                </a>
                <a href="?date={{ now()->format('Y-m-d') }}"
                   style="background:#f97316; color:#fff; border-radius:8px; padding:6px 16px; text-decoration:none; font-size:13px; font-weight:700; box-shadow:0 2px 6px rgba(249,115,22,0.3);">
                    Сьогодні
                </a>
                <a href="?date={{ \Carbon\Carbon::parse($rawDate)->addDay()->format('Y-m-d') }}"
                   style="background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; border-radius:8px; padding:6px 14px; text-decoration:none; font-size:13px; font-weight:500;">
                    Завтра →
                </a>
            </div>
        </div>
    </div>

    {{-- CONTENT --}}
    <div style="max-width: 900px; margin: 0 auto; padding: 28px 16px;">

        @if($dishes->isEmpty())
            <div style="text-align:center; padding: 60px 20px; color: #94a3b8;">
                <div style="font-size: 48px; margin-bottom: 12px;">🍽️</div>
                <div style="font-size: 18px; font-weight: 600;">Меню на цей день не знайдено</div>
            </div>
        @else
            @foreach($dishes as $mealName => $items)
                @php
                    $ml = mb_strtolower(trim($mealName));
                    $mealClass = 'meal-default';
                    if (str_contains($ml, 'сніданок'))      $mealClass = 'meal-snidanok';
                    elseif (str_contains($ml, 'перекус 1')) $mealClass = 'meal-perekus1';
                    elseif (str_contains($ml, 'обід'))       $mealClass = 'meal-obid';
                    elseif (str_contains($ml, 'перекус 2')) $mealClass = 'meal-perekus2';
                    elseif (str_contains($ml, 'вечеря'))     $mealClass = 'meal-vecherya';
                @endphp

                {{-- MEAL SECTION HEADER --}}
                <div style="display: flex; align-items: center; gap: 12px; margin: 32px 0 14px;">
                    <span class="meal-badge {{ $mealClass }}">{{ $mealName }}</span>
                    <div style="flex:1; height:1px; background:#e2e8f0;"></div>
                    <span style="color:#94a3b8; font-size:12px;">{{ $items->count() }} {{ $items->count() === 1 ? 'страва' : 'страви' }}</span>
                </div>

                {{-- DISH CARDS --}}
                <div style="display: flex; flex-direction: column; gap: 14px;">
                    @foreach($items as $item)
                        @php $dish = $item->dish; @endphp
                        <div class="dish-card">

                            {{-- DISH HEADER --}}
                            <div style="display: flex; gap: 16px; padding: 16px 18px;">
                                {{-- Photo --}}
                                <div style="flex-shrink: 0;">
                                    @if($dish->photo)
                                        <img src="{{ asset('storage/' . $dish->photo) }}"
                                             alt="{{ $dish->name }}"
                                             style="width:88px; height:88px; border-radius:12px; object-fit:cover; border:1px solid #e2e8f0;">
                                    @else
                                        <div style="width:88px; height:88px; border-radius:12px; background:#f8fafc; display:flex; align-items:center; justify-content:center; font-size:32px; border:1px solid #e2e8f0;">
                                            🍽️
                                        </div>
                                    @endif
                                </div>

                                {{-- Dish info --}}
                                <div style="flex: 1; min-width: 0; padding-top: 4px;">
                                    <div style="font-size: 19px; font-weight: 800; color: #0f172a; line-height: 1.3; margin-bottom: 8px;">
                                        {{ $dish->name }}
                                    </div>
                                    <div style="display: flex; gap: 14px; flex-wrap: wrap; align-items: center;">
                                        @if($dish->base_weight_g)
                                            <span style="font-size:12px; color:#334155; font-weight:600; background:#f1f5f9; border-radius:6px; padding:2px 8px;">
                                                ⚖️ {{ $dish->base_weight_g }}г
                                            </span>
                                        @endif
                                        @if($dish->total_kcal ?? null)
                                            <span style="font-size:12px; color:#92400e; font-weight:600; background:#fff7ed; border-radius:6px; padding:2px 8px;">
                                                🔥 {{ round($dish->total_kcal) }} ккал
                                            </span>
                                        @endif
                                        @if($dish->is_semi_finished)
                                            <span style="font-size:11px; background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; border-radius:6px; padding:1px 8px; font-weight:700;">Н/Ф</span>
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- RECIPE --}}
                            @if($dish->description)
                                <div style="border-top: 1px solid #f1f5f9; padding: 14px 18px; background: #fafafa;">
                                    <div style="font-size: 10px; font-weight: 700; color: #f97316; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 10px;">
                                        Рецепт приготування
                                    </div>
                                    <div class="recipe-content" style="font-size: 14px; line-height: 1.7;">
                                        {!! $dish->description !!}
                                    </div>
                                </div>
                            @else
                                <div style="border-top: 1px solid #f1f5f9; padding: 10px 18px; background: #fafafa;">
                                    <span style="font-size: 13px; color: #94a3b8; font-style: italic;">Рецепт не додано</span>
                                </div>
                            @endif

                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif

        <div style="height: 40px;"></div>
    </div>

</body>
</html>
