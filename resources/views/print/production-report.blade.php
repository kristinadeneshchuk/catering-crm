<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>План виробництва — {{ \Carbon\Carbon::parse($targetDate)->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* БАЗОВІ СТИЛІ ТАБЛИЦЬ ТА ДРУКУ */
        body { 
            font-family: DejaVu Sans, sans-serif; 
            font-size: 11px; 
            -webkit-print-color-adjust: exact !important; 
            print-color-adjust: exact !important; 
            color: #111827; 
            background: #fff;
        }

        table { width: 100%; border-collapse: collapse; margin: 0; }
        th, td { border: 1px solid #d1d5db; padding: 4px 8px; text-align: left; }
        th { text-align: center; font-size: 10px; background-color: #ffffff !important; color: #111827; }

        /* 🔥 СІРА "ЗЕБРА" ДЛЯ ІНГРЕДІЄНТІВ 🔥 */
        .table-striped tbody tr:nth-child(even) td { background-color: #e5e7eb !important; } 
        .table-striped tbody tr:nth-child(odd) td { background-color: #f9fafb !important; } 

        /* 🔥 КОЛЬОРИ ПРИЙОМІВ ЇЖІ 🔥 */
        .meal-header { padding: 8px 12px; font-weight: bold; font-size: 15px; text-transform: uppercase; margin-top: 25px; margin-bottom: 15px; border-radius: 4px; }
        
        .meal-snidanok { background-color: #14b8a6 !important; color: white !important; text-shadow: 0px 1px 1px rgba(0,0,0,0.3); } 
        .meal-perekus-1 { background-color: #a3e635 !important; color: black !important; } 
        .meal-obid { background-color: #fb923c !important; color: white !important; text-shadow: 0px 1px 1px rgba(0,0,0,0.3); } 
        .meal-perekus-2 { background-color: #f472b6 !important; color: white !important; text-shadow: 0px 1px 1px rgba(0,0,0,0.3); } 
        .meal-vecherya { background-color: #38bdf8 !important; color: white !important; text-shadow: 0px 1px 1px rgba(0,0,0,0.3); } 
        .meal-default { background-color: #94a3b8 !important; color: white !important; }

        /* СІТКА ДЛЯ КАРТОК (БАЗА + ПФ) */
        .pf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .pf-card { border: 1px solid #d1d5db; break-inside: avoid; border-radius: 4px; overflow: hidden; }
        .pf-card-header { padding: 5px 10px; font-weight: bold; font-size: 12px; border-bottom: 1px solid #ccc; display: flex; justify-content: space-between; }
        
        .dish-title { font-size: 14px; font-weight: bold; margin-bottom: 10px; border-bottom: 2px solid #ccc; padding-bottom: 4px; margin-top: 20px;}
        .sum-row td { background-color: #d1d5db !important; font-weight: bold; }
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .font-bold { font-weight: bold !important; }

        /* РЕЦЕПТ ПРИГОТУВАННЯ */
        .recipe-box {
            margin: 0 0 18px 0;
            border: 1px solid #fde68a;
            background: #fffbeb;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: 8px 12px;
            font-size: 11px;
            line-height: 1.55;
            color: #1f2937;
            break-inside: avoid;
        }
        .recipe-box .recipe-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #92400e;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .recipe-box .recipe-title::before {
            content: "👨‍🍳";
            font-size: 12px;
        }
        .recipe-box .recipe-content h2,
        .recipe-box .recipe-content h3 { font-size: 11px; font-weight: 800; margin: 6px 0 3px; color: #111827; }
        .recipe-box .recipe-content ul,
        .recipe-box .recipe-content ol { padding-left: 18px; margin: 4px 0; }
        .recipe-box .recipe-content li { margin: 2px 0; }
        .recipe-box .recipe-content p  { margin: 3px 0; }
        .recipe-box .recipe-content strong { color: #111827; }
        .recipe-box .recipe-content blockquote {
            border-left: 3px solid #f59e0b;
            padding: 2px 8px;
            margin: 4px 0;
            color: #4b5563;
            font-style: italic;
        }

        /* СПЕЦІАЛЬНІ СТИЛІ ДРУКУ */
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 !important; margin: 0 !important; }
            .pf-grid { gap: 8px; margin-bottom: 10px; }
            th, td { padding: 2px 4px !important; font-size: 10px !important; }
            .meal-header { padding: 4px 8px !important; font-size: 13px !important; margin-top: 15px;}
            .recipe-box { font-size: 10px !important; padding: 6px 10px !important; }
        }
    </style>
</head>
<body class="bg-gray-100 p-4 sm:p-8 text-slate-800 font-sans print:bg-white print:p-0">

    @php
        // 🔥 РЕКУРСИВНА ФУНКЦІЯ: Витягує всі ПФ з дерева у плоский список для окремих карток
        $flattenPFs = function($structure) use (&$flattenPFs) {
            $flattened = [];
            foreach($structure as $item) {
                if (($item['type'] ?? '') === 'pf') {
                    $flattened[] = $item;
                    if (!empty($item['sub_ingredients'])) {
                        $flattened = array_merge($flattened, $flattenPFs($item['sub_ingredients']));
                    }
                }
            }
            return $flattened;
        };
    @endphp

    {{-- КНОПКА ДРУКУ --}}
    <div class="no-print mb-6 flex justify-between items-center max-w-5xl mx-auto bg-white p-4 rounded-xl shadow" style="gap:16px; flex-wrap:wrap;">
        <div>
            <h1 class="text-xl font-bold">План виробництва</h1>
            <p class="text-sm text-gray-500">Готуємо сьогодні ({{ $date }}) на завтра ({{ \Carbon\Carbon::parse($targetDate)->format('d.m.Y') }})</p>
        </div>
        <button onclick="window.print()" class="bg-yellow-500 hover:bg-yellow-600 text-black px-6 py-2 rounded-lg font-bold shadow transition">
            Роздрукувати звіт
        </button>
    </div>

    {{-- ГОЛОВНИЙ КОНТЕЙНЕР --}}
    <div class="max-w-5xl mx-auto bg-white p-4 sm:p-8 rounded-xl shadow print:shadow-none print:w-full">
        <h2 class="text-xl sm:text-2xl font-black text-center mb-6 uppercase border-b pb-4">
            План кухні на {{ \Carbon\Carbon::parse($targetDate)->format('d.m.Y') }}
        </h2>

        @if(!empty($missingPlans ?? []))
            <div style="background:#fee2e2; border:2px solid #ef4444; border-radius:8px; padding:12px 16px; margin-bottom:18px; color:#7f1d1d; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                <div style="font-weight:900; font-size:13px; margin-bottom:6px; text-transform:uppercase;">⚠️ Не вистачає меню для деяких планів</div>
                @foreach($missingPlans as $mp)
                    <div style="margin-bottom:4px; font-size:12px;">
                        <strong>{{ $mp['plan']->name }}</strong> — день №{{ $mp['day_number'] }} циклу не створено.
                        Зачеплено клієнтів: <strong>{{ $mp['orders_count'] }}</strong>
                        @if(!empty($mp['client_names']))
                            ({{ implode(', ', $mp['client_names']) }}@if($mp['orders_count'] > count($mp['client_names'])), …@endif)
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        @if(empty($report))
            <p class="text-center text-gray-500 py-10">Немає замовлень або страв для приготування на цю дату.</p>
        @else
            @php
                // Збираємо всі унікальні коментарі з усіх планів і страв (один раз на день)
                $allDayComments = [];
                foreach($report as $planData) {
                    foreach(($planData['meals'] ?? []) as $mealGroup) {
                        foreach($mealGroup as $dishRow) {
                            foreach($dishRow['comment_clients'] ?? [] as $cc) {
                                $allDayComments[$cc['client_name']] = $cc; // дедуплікація по клієнту
                            }
                        }
                    }
                }
            @endphp

            {{-- КОМЕНТАРІ ДО ВИРОБНИЦТВА — один раз на всю сторінку --}}
            @if(!empty($allDayComments))
                <div style="margin-bottom: 20px; border: 1px solid #fcd34d; background-color: #fffbeb; border-radius: 4px; padding: 8px 12px;">
                    <div style="font-weight: bold; font-size: 12px; color: #92400e; margin-bottom: 6px;">Коментарі до виробництва:</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2px 20px;">
                        @foreach($allDayComments as $cc)
                            <div style="font-size: 10px; border-bottom: 1px dashed #fde68a; padding: 2px 0;">
                                <strong>{{ $cc['client_name'] }}</strong> — {{ $cc['comment'] }}
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ОБХІД ПО ПЛАНАХ МЕНЮ --}}
            @foreach($report as $planId => $planData)
                @php
                    $kitchenUrl = url('/kitchen?date=' . $targetDate . '&plan_id=' . $planId);
                    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . urlencode($kitchenUrl);
                @endphp
                <div style="margin-top:24px; margin-bottom:18px; padding:12px 16px; background:#f5f3ff; border:2px solid #c4b5fd; border-radius:10px; -webkit-print-color-adjust:exact; print-color-adjust:exact; page-break-before:auto; page-break-inside:avoid;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;">
                        <div>
                            <div style="font-size:10px; font-weight:800; color:#5b21b6; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:2px;">План меню</div>
                            <div style="font-size:18px; font-weight:900; color:#1e1b4b;">{{ $planData['plan']->name }}</div>
                            <div style="font-size:11px; color:#5b21b6; margin-top:2px;">День циклу №{{ $planData['day_number'] }} з {{ $planData['plan']->cycle_days }}</div>
                        </div>
                        <div class="no-print" style="display:flex; flex-direction:column; align-items:center; gap:3px;">
                            <img src="{{ $qrUrl }}" alt="QR" style="width:64px; height:64px; border:1px solid #c4b5fd; border-radius:6px;">
                            <a href="{{ $kitchenUrl }}" target="_blank" style="font-size:9px; color:#5b21b6; text-decoration:none;">Меню кухні</a>
                        </div>
                    </div>
                </div>

                @foreach(($planData['meals'] ?? []) as $mealName => $dishes)
                @php
                    $mealLower = mb_strtolower(trim($mealName));
                    $mealClass = 'meal-default';
                    if (str_contains($mealLower, 'сніданок')) $mealClass = 'meal-snidanok';
                    elseif (str_contains($mealLower, 'перекус 1')) $mealClass = 'meal-perekus-1';
                    elseif (str_contains($mealLower, 'обід')) $mealClass = 'meal-obid';
                    elseif (str_contains($mealLower, 'перекус 2')) $mealClass = 'meal-perekus-2';
                    elseif (str_contains($mealLower, 'вечеря')) $mealClass = 'meal-vecherya';
                @endphp

                <div class="meal-header {{ $mealClass }}">{{ $mealName }}</div>

                @foreach($dishes as $dish)
                    @php
                        $customCount = count($dish['custom_cards'] ?? []);
                    @endphp
                    <div class="dish-title">
                        {{ $dish['dish_name'] }}
                        <span class="font-normal text-sm text-gray-500 ml-2">
                            (Всього: {{ $dish['standard_count'] }} шт
                            @if($customCount > 0), з них {{ $customCount }} інд.@endif)
                        </span>
                    </div>

                    {{-- СТАНДАРТНІ ПОРЦІЇ ТА ЇХ ПФ --}}
                    @if($dish['standard_count'] > 0)
                        @php
                            $allPFs = $flattenPFs($dish['standard_structure']);
                        @endphp

                        <div class="pf-grid">
                            {{-- 1. БАЗОВА СТРАВА (Головна картка) --}}
                            <div class="pf-card">
                                <div class="pf-card-header {{ $mealClass }}">
                                    <span>{{ $dish['dish_name'] }} > [Базовий]</span>
                                    <span>{{ $dish['standard_count'] }} шт.</span>
                                </div>
                                <table class="table-striped">
                                    <thead>
                                        <tr>
                                            <th>Інгредієнт/ПФ</th>
                                            <th style="width: 50px;">Брутто</th>
                                            <th style="width: 50px;">Нетто</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($dish['standard_structure'] as $item)
                                            <tr>
                                                <td class="{{ $item['type'] === 'pf' ? 'font-bold' : '' }}">
                                                    {{ $item['name'] }}
                                                </td>
                                                <td class="text-center font-bold">{{ round($item['weight_brutto_sum'] ?? $item['weight_brutto'] ?? 0) }}</td>
                                                <td class="text-center">{{ round($item['weight_netto_sum'] ?? $item['weight_netto'] ?? $item['weight_output'] ?? 0) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="sum-row">
                                            <td class="text-right">Сумарна вага:</td>
                                            <td class="text-center">{{ round($dish['standard_total_brutto']) }}</td>
                                            <td class="text-center">{{ round($dish['standard_total_netto']) }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {{-- 2. ОКРЕМІ КАРТКИ ДЛЯ КОЖНОГО НАПІВФАБРИКАТУ --}}
                            @foreach($allPFs as $pf)
                                <div class="pf-card">
                                    <div class="pf-card-header {{ $mealClass }}">
                                        <span>{{ $pf['name'] }} > [Базовий]</span>
                                    </div>
                                    <table class="table-striped">
                                        <thead>
                                            <tr>
                                                <th>Інгредієнт/ПФ</th>
                                                <th style="width: 50px;">Брутто</th>
                                                <th style="width: 50px;">Нетто</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($pf['sub_ingredients'] as $item)
                                                <tr>
                                                    <td class="{{ $item['type'] === 'pf' ? 'font-bold' : '' }}">
                                                        {{ $item['name'] }}
                                                    </td>
                                                    <td class="text-center font-bold">{{ round($item['weight_brutto_sum'] ?? $item['weight_brutto'] ?? 0) }}</td>
                                                    <td class="text-center">{{ round($item['weight_netto_sum'] ?? $item['weight_netto'] ?? $item['weight_output'] ?? 0) }}</td>
                                                </tr>
                                            @endforeach
                                            <tr class="sum-row">
                                                <td class="text-right">Вихід ПФ:</td>
                                                <td class="text-center">-</td>
                                                <td class="text-center">{{ round($pf['weight_output'] ?? 0) }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- РЕЦЕПТ ПРИГОТУВАННЯ --}}
                    @if(!empty(trim(strip_tags($dish['recipe'] ?? ''))))
                        <div class="recipe-box">
                            <div class="recipe-title">Рецепт приготування</div>
                            <div class="recipe-content">{!! $dish['recipe'] !!}</div>
                        </div>
                    @endif

                    {{-- 3. ІНДИВІДУАЛЬНІ ЗАМОВЛЕННЯ (КАСТОМ) — компактна таблиця змін --}}
                    @if(count($dish['custom_cards']) > 0)
                        <div style="margin-bottom: 16px;">
                            <h4 class="font-bold text-red-600 mb-2 uppercase text-xs border-b border-red-200 pb-1 print:text-black">
                                Індивідуальні замовлення (Заміни)
                            </h4>

                            {{-- ТЕХКАРТИ ПОВНИХ ЗАМІН СТРАВ (згруповані та просумовані) --}}
                            @php
                                $replacementGroups = collect($dish['custom_cards'])
                                    ->filter(fn($c) => !empty($c['dish_replacement']))
                                    ->groupBy('dish_replacement');
                            @endphp
                            @if($replacementGroups->isNotEmpty())
                                <div class="pf-grid" style="margin-bottom: 12px;">
                                @foreach($replacementGroups as $replacementName => $repCards)
                                    @php
                                        // Сумуємо інгредієнти по всіх клієнтах з однаковою заміною
                                        $summed = [];
                                        foreach ($repCards as $rc) {
                                            foreach ($rc['components'] as $comp) {
                                                $key = $comp['name'];
                                                if (!isset($summed[$key])) {
                                                    $summed[$key] = [
                                                        'name'   => $comp['name'],
                                                        'type'   => $comp['type'] ?? 'product',
                                                        'brutto' => 0,
                                                        'netto'  => 0,
                                                        'sub_ingredients' => $comp['sub_ingredients'] ?? [],
                                                    ];
                                                }
                                                $summed[$key]['brutto'] += (float)($comp['weight_brutto_sum'] ?? $comp['weight_brutto'] ?? 0);
                                                $summed[$key]['netto']  += (float)($comp['weight_netto_sum'] ?? $comp['weight_netto'] ?? $comp['weight_output'] ?? 0);
                                            }
                                        }
                                        $repClientNames = $repCards->pluck('client_name')->join(', ');
                                        $repCount = $repCards->count();

                                        // PF картки всередині цієї заміни
                                        $repPFs = collect($summed)->filter(fn($i) => $i['type'] === 'pf')->values();
                                    @endphp

                                    {{-- Головна картка заміни --}}
                                    <div class="pf-card" style="border-color: #2563eb;">
                                        <div class="pf-card-header" style="background: #2563eb; color: white;">
                                            <span>{{ $replacementName }} > [Заміна]</span>
                                            <span>{{ $repCount }} шт.</span>
                                        </div>
                                        <div style="padding: 3px 8px; font-size: 9px; color: #1e40af; background: #eff6ff; border-bottom: 1px solid #bfdbfe;">
                                            {{ $repClientNames }}
                                        </div>
                                        <table class="table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Інгредієнт/ПФ</th>
                                                    <th style="width:50px;">Брутто</th>
                                                    <th style="width:50px;">Нетто</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($summed as $ing)
                                                    <tr>
                                                        <td class="{{ $ing['type'] === 'pf' ? 'font-bold' : '' }}">{{ $ing['name'] }}</td>
                                                        <td class="text-center font-bold">{{ round($ing['brutto']) }}</td>
                                                        <td class="text-center">{{ round($ing['netto']) }}</td>
                                                    </tr>
                                                @endforeach
                                                <tr class="sum-row">
                                                    <td class="text-right">Сумарна вага:</td>
                                                    <td class="text-center">{{ round(array_sum(array_column($summed, 'brutto'))) }}</td>
                                                    <td class="text-center">{{ round(array_sum(array_column($summed, 'netto'))) }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    {{-- ПФ картки для заміни --}}
                                    @foreach($repPFs as $repPf)
                                        @php
                                            // Сумуємо sub_ingredients цього ПФ по всіх клієнтах
                                            $pfSummed = [];
                                            foreach ($repCards as $rc) {
                                                foreach ($rc['components'] as $comp) {
                                                    if ($comp['name'] !== $repPf['name'] || ($comp['type'] ?? '') !== 'pf') continue;
                                                    foreach ($comp['sub_ingredients'] ?? [] as $sub) {
                                                        $sk = $sub['name'];
                                                        if (!isset($pfSummed[$sk])) {
                                                            $pfSummed[$sk] = ['name' => $sub['name'], 'brutto' => 0, 'netto' => 0, 'type' => $sub['type'] ?? 'product'];
                                                        }
                                                        $pfSummed[$sk]['brutto'] += (float)($sub['weight_brutto_sum'] ?? $sub['weight_brutto'] ?? 0);
                                                        $pfSummed[$sk]['netto']  += (float)($sub['weight_netto_sum'] ?? $sub['weight_netto'] ?? $sub['weight_output'] ?? 0);
                                                    }
                                                }
                                            }
                                        @endphp
                                        @if(!empty($pfSummed))
                                        <div class="pf-card" style="border-color: #2563eb;">
                                            <div class="pf-card-header" style="background: #1d4ed8; color: white;">
                                                <span>{{ $repPf['name'] }} > [Заміна]</span>
                                            </div>
                                            <table class="table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Інгредієнт/ПФ</th>
                                                        <th style="width:50px;">Брутто</th>
                                                        <th style="width:50px;">Нетто</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($pfSummed as $ps)
                                                        <tr>
                                                            <td>{{ $ps['name'] }}</td>
                                                            <td class="text-center font-bold">{{ round($ps['brutto']) }}</td>
                                                            <td class="text-center">{{ round($ps['netto']) }}</td>
                                                        </tr>
                                                    @endforeach
                                                    <tr class="sum-row">
                                                        <td class="text-right">Вихід ПФ:</td>
                                                        <td class="text-center">-</td>
                                                        <td class="text-center">{{ round($repPf['netto']) }}</td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        @endif
                                    @endforeach
                                @endforeach
                                </div>
                            @endif

                            <table style="width: auto; min-width: 55%; border-collapse: collapse;">
                                <thead>
                                    <tr>
                                        <th style="width: 160px; background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 8px; font-size: 10px;">Клієнт</th>
                                        <th style="background: #f3f4f6; border: 1px solid #d1d5db; padding: 4px 8px; font-size: 10px; text-align: left;">Зміни</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($dish['custom_cards'] as $card)
                                        @php
                                            $exceptions = [];

                                            if ($card['dish_excluded']) {
                                                $exceptions[] = '<span style="color:#dc2626;font-weight:bold;">СТРАВУ ВИКЛЮЧЕНО</span>';
                                            } elseif ($card['dish_replacement']) {
                                                $exceptions[] = '<span style="color:#2563eb;font-weight:bold;">Заміна страви: ' . e($card['dish_replacement']) . '</span>';
                                            } else {
                                                // Root-level ingredient changes
                                                foreach ($card['components'] as $item) {
                                                    if (($item['type'] ?? '') === 'pf') continue;
                                                    $isConflict = is_array($item['conflict'] ?? null);
                                                    $isResolved = $isConflict && ($item['conflict']['is_resolved'] ?? false);
                                                    if ($isResolved) {
                                                        $brutto = round($item['conflict']['replacement']['brutto'] ?? 0);
                                                        $exceptions[] = '<span style="text-decoration:line-through;color:#9ca3af;">' . e($item['name']) . '</span> → <b>' . e($item['conflict']['replacement']['name'] ?? '?') . '</b> (' . $brutto . 'г)';
                                                    } elseif ($isConflict) {
                                                        $brutto = round($item['weight_brutto_sum'] ?? $item['weight_brutto'] ?? 0);
                                                        $allergen = !empty($item['conflict']['allergen']) ? ' <span style="color:#ea580c;font-size:9px;">[' . e($item['conflict']['allergen']) . ']</span>' : '';
                                                        $exceptions[] = '<span style="text-decoration:line-through;color:#9ca3af;">' . e($item['name']) . '</span> <span style="color:#dc2626;font-weight:bold;">БЕЗ</span>' . $allergen . ' (' . $brutto . 'г)';
                                                    }
                                                }
                                                // PF sub-ingredient changes
                                                foreach ($card['components'] as $item) {
                                                    if (($item['type'] ?? '') !== 'pf') continue;
                                                    foreach ($item['sub_ingredients'] ?? [] as $sub) {
                                                        $isSubConflict = is_array($sub['conflict'] ?? null);
                                                        $isSubResolved = $isSubConflict && ($sub['conflict']['is_resolved'] ?? false);
                                                        if ($isSubResolved) {
                                                            $brutto = round($sub['conflict']['replacement']['brutto'] ?? 0);
                                                            $exceptions[] = '[' . e($item['name']) . '] <span style="text-decoration:line-through;color:#9ca3af;">' . e($sub['name']) . '</span> → <b>' . e($sub['conflict']['replacement']['name'] ?? '?') . '</b> (' . $brutto . 'г)';
                                                        } elseif ($isSubConflict) {
                                                            $brutto = round($sub['weight_brutto_sum'] ?? $sub['weight_brutto'] ?? 0);
                                                            $allergen = !empty($sub['conflict']['allergen']) ? ' <span style="color:#ea580c;font-size:9px;">[' . e($sub['conflict']['allergen']) . ']</span>' : '';
                                                            $exceptions[] = '[' . e($item['name']) . '] <span style="text-decoration:line-through;color:#9ca3af;">' . e($sub['name']) . '</span> <span style="color:#dc2626;font-weight:bold;">БЕЗ</span>' . $allergen . ' (' . $brutto . 'г)';
                                                        }
                                                    }
                                                }
                                            }
                                        @endphp
                                        <tr>
                                            <td style="border: 1px solid #d1d5db; padding: 4px 8px; vertical-align: top; white-space: nowrap; font-weight: bold; font-size: 10px; background: #f9fafb;">
                                                {{ $card['client_name'] }}<br>
                                                <span style="font-weight:normal;color:#6b7280;font-size:9px;">Зам. #{{ $card['order_id'] }}</span>
                                            </td>
                                            <td style="border: 1px solid #d1d5db; padding: 4px 8px; font-size: 10px;">
                                                @if(empty($exceptions))
                                                    <span style="color:#9ca3af;font-style:italic;">без змін</span>
                                                @else
                                                    @foreach($exceptions as $ex)
                                                        <div>{!! $ex !!}</div>
                                                    @endforeach
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif

                @endforeach {{-- $dishes --}}
                @endforeach {{-- $planData['meals'] --}}

                {{-- ІНДИВІДУАЛЬНІ КЛІЄНТИ цього плану --}}
                @if(!empty($planData['individuals']))
            <div style="margin-top:24px; border-top:3px solid #7c3aed; padding-top:16px;">
                <div style="background:#7c3aed; color:white; padding:6px 12px; font-weight:900; font-size:13px; text-transform:uppercase; margin-bottom:12px; border-radius:4px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                    ★ Індивідуальні клієнти ({{ $planData['plan']->name }})
                </div>
                @foreach(array_values($planData['individuals']) as $client)
                    <div style="border:2px solid #7c3aed; border-radius:6px; overflow:hidden; margin-bottom:16px; page-break-inside:avoid;">
                        {{-- Шапка клієнта --}}
                        <div style="background:#7c3aed; color:white; padding:6px 12px; font-size:12px; font-weight:900; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                            {{ $client['client_label'] }}
                            <span style="margin-left:10px; font-size:10px; font-weight:500; opacity:0.85;">{{ $client['project'] }}</span>
                            <span style="margin-left:8px; font-size:10px; font-weight:700; background:rgba(255,255,255,0.2); padding:1px 6px; border-radius:3px;">{{ $client['calories'] }} ккал</span>
                        </div>
                        {{-- Прийоми їжі в рядок --}}
                        <table style="width:100%; border-collapse:collapse; margin:0;">
                            <tbody>
                                <tr style="vertical-align:top;">
                                    @foreach($client['meals'] as $meal)
                                        @php
                                            $ml = mb_strtolower(trim($meal['meal']));
                                            $mc = '#94a3b8';
                                            if (str_contains($ml, 'сніданок'))      $mc = '#14b8a6';
                                            elseif (str_contains($ml, 'перекус 1')) $mc = '#84cc16';
                                            elseif (str_contains($ml, 'обід'))      $mc = '#fb923c';
                                            elseif (str_contains($ml, 'перекус 2')) $mc = '#f472b6';
                                            elseif (str_contains($ml, 'вечеря'))    $mc = '#38bdf8';
                                        @endphp
                                        <td style="vertical-align:top; padding:0; border:1px solid #e5e7eb; width:{{ round(100 / count($client['meals'])) }}%;">
                                            <div style="background:{{ $mc }}; color:white; padding:3px 8px; font-weight:900; font-size:10px; text-transform:uppercase; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                                {{ $meal['meal'] }}
                                            </div>
                                            <div style="background:#dcfce7; padding:4px 8px; border-bottom:1px solid #bbf7d0; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                                <div style="font-weight:900; font-size:11px; color:#052e16;">{{ $meal['dish_name'] }}</div>
                                                <div style="font-size:9px; color:#065f46;">Нетто: {{ $meal['total_netto'] }}г / Брутто: {{ $meal['total_brutto'] }}г</div>
                                            </div>
                                            <table style="width:100%; border-collapse:collapse; margin:0; font-size:10px;">
                                                <tbody>
                                                    @foreach($meal['components'] as $comp)
                                                        @if(($comp['type'] ?? '') === 'pf')
                                                            <tr style="background:#f3f4f6; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                                                <td colspan="2" style="padding:2px 6px; color:#6b7280; font-style:italic; font-size:9px; border-bottom:1px solid #e5e7eb;">
                                                                    НФ: {{ $comp['name'] }} ({{ round($comp['weight_output'] ?? 0) }}г)
                                                                </td>
                                                            </tr>
                                                            @foreach($comp['sub_ingredients'] ?? [] as $sub)
                                                                <tr>
                                                                    <td style="padding:2px 6px 2px 14px; border-bottom:1px solid #f3f4f6; color:#374151; font-size:9px;">{{ $sub['name'] }}</td>
                                                                    <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; width:45px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">{{ round($sub['weight_brutto'] ?? 0) }} г</td>
                                                                </tr>
                                                            @endforeach
                                                        @else
                                                            <tr>
                                                                <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; color:#374151;">{{ $comp['name'] }}</td>
                                                                <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; width:45px; -webkit-print-color-adjust:exact; print-color-adjust:exact;">{{ round($comp['weight_brutto'] ?? 0) }} г</td>
                                                            </tr>
                                                        @endif
                                                    @endforeach
                                                </tbody>
                                            </table>
                                            @if(!empty(trim(strip_tags($meal['recipe'] ?? ''))))
                                                <div style="border-top:1px dashed #fde68a; background:#fffbeb; padding:5px 8px; font-size:9px; line-height:1.45; color:#1f2937; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                                                    <div style="font-weight:800; color:#92400e; font-size:8px; text-transform:uppercase; letter-spacing:0.4px; margin-bottom:2px;">Рецепт</div>
                                                    <div class="recipe-content">{!! $meal['recipe'] !!}</div>
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            </tbody>
                        </table>
                    </div>
                @endforeach
            </div>
                @endif
            @endforeach
        @endif
    </div>
</body>
</html>