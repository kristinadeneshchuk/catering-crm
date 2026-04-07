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

        /* СПЕЦІАЛЬНІ СТИЛІ ДРУКУ */
        @media print {
            .no-print { display: none !important; }
            body { padding: 0 !important; margin: 0 !important; }
            .pf-grid { gap: 8px; margin-bottom: 10px; }
            th, td { padding: 2px 4px !important; font-size: 10px !important; }
            .meal-header { padding: 4px 8px !important; font-size: 13px !important; margin-top: 15px;}
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
    <div class="no-print mb-6 flex justify-between items-center max-w-5xl mx-auto bg-white p-4 rounded-xl shadow">
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

        @if(empty($report))
            <p class="text-center text-gray-500 py-10">Немає замовлень або страв для приготування на цю дату.</p>
        @else
            @php
                // Збираємо всі унікальні коментарі з усіх страв (один раз на день)
                $allDayComments = [];
                foreach($report as $mealGroup) {
                    foreach($mealGroup as $dishRow) {
                        foreach($dishRow['comment_clients'] ?? [] as $cc) {
                            $allDayComments[$cc['client_name']] = $cc; // дедуплікація по клієнту
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

            @foreach($report as $mealName => $dishes)
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

                    {{-- 3. ІНДИВІДУАЛЬНІ ЗАМОВЛЕННЯ (КАСТОМ) — компактна таблиця змін --}}
                    @if(count($dish['custom_cards']) > 0)
                        <div style="margin-bottom: 16px;">
                            <h4 class="font-bold text-red-600 mb-2 uppercase text-xs border-b border-red-200 pb-1 print:text-black">
                                Індивідуальні замовлення (Заміни)
                            </h4>

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

                @endforeach
            @endforeach
        @endif
    </div>
</body>
</html>