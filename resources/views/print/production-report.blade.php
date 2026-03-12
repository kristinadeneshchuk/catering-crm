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
            🖨 Роздрукувати звіт
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
                    <div class="dish-title">{{ $dish['dish_name'] }} <span class="font-normal text-sm text-gray-500 ml-2">(Стандарт: {{ $dish['standard_count'] }} шт)</span></div>

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
                                                    {{ $item['type'] === 'pf' ? '📦 ' : '' }}{{ $item['name'] }}
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
                                                        {{ $item['type'] === 'pf' ? '📦 ' : '' }}{{ $item['name'] }}
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

                    {{-- 3. ІНДИВІДУАЛЬНІ ЗАМОВЛЕННЯ (КАСТОМ) --}}
                    @if(count($dish['custom_cards']) > 0)
                        <div>
                            <h4 class="font-bold text-red-600 mb-2 uppercase text-xs border-b border-red-200 pb-1 print:text-black">
                                ⚠️ Індивідуальні замовлення (Заміни)
                            </h4>
                            
                            <div class="pf-grid">
                                @foreach($dish['custom_cards'] as $card)
                                    {{-- ГОЛОВНА КАРТКА КАСТОМНОЇ СТРАВИ --}}
                                    <div class="pf-card border-red-300">
                                        <div class="pf-card-header bg-gray-700 text-white print:bg-gray-200 print:text-black">
                                            <span>👤 {{ $card['client_name'] }} (Зам. #{{ $card['order_id'] }})</span>
                                        </div>
                                        
                                        <div class="p-1.5 bg-orange-50 text-orange-800 text-[10px] border-b border-gray-300 print:bg-white print:text-black">
                                            @if($card['dish_excluded']) <span class="font-bold text-red-600 print:text-black">❌ СТРАВУ ВИКЛЮЧЕНО</span> <br> @endif
                                            @if($card['dish_replacement']) <span class="font-bold text-blue-600 print:text-black">🔄 Заміна на: {{ $card['dish_replacement'] }}</span> <br> @endif
                                            @if($card['comment']) <span class="font-bold">📝 Коментар:</span> {{ $card['comment'] }} @endif
                                        </div>

                                        @if(!$card['dish_excluded'] || $card['dish_replacement'])
                                            <table class="table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Інгредієнт</th>
                                                        <th style="width: 40px;">Брутто</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach($card['components'] as $item)
                                                        @php 
                                                            $isConflict = is_array($item['conflict'] ?? null);
                                                            $isResolved = $isConflict && ($item['conflict']['is_resolved'] ?? false);
                                                        @endphp
                                                        <tr>
                                                            <td class="{{ ($item['type'] ?? '') === 'pf' ? 'font-bold' : '' }}">
                                                                {{ ($item['type'] ?? '') === 'pf' ? '📦 ' : '' }}
                                                                @if($isResolved)
                                                                    <span class="line-through text-gray-400">{{ $item['name'] }}</span><br>
                                                                    <span class="font-bold text-[9px] text-blue-600">🔄 На: {{ $item['conflict']['replacement']['name'] ?? 'Заміна' }}</span>
                                                                @elseif($isConflict)
                                                                    <span class="line-through text-red-400">{{ $item['name'] }}</span><br>
                                                                    <span class="font-bold text-[9px] text-red-600">❌ БЕЗ</span>
                                                                @else
                                                                    {{ $item['name'] }}
                                                                @endif
                                                            </td>
                                                            <td class="text-center font-bold">
                                                                {{ round($isResolved ? ($item['conflict']['replacement']['brutto'] ?? 0) : ($item['weight_brutto_sum'] ?? $item['weight_brutto'] ?? 0)) }}
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        @endif
                                    </div>

                                    {{-- 🔥 ОКРЕМІ КАРТКИ ДЛЯ КАСТОМНИХ ПФ 🔥 --}}
                                    @if(!$card['dish_excluded'] || $card['dish_replacement'])
                                        @php
                                            $customPFs = $flattenPFs($card['components']);
                                        @endphp
                                        
                                        @foreach($customPFs as $pf)
                                            @php
                                                // Збираємо список замін саме для цього ПФ, щоб перевірити, чи треба його виводити
                                                $changes = [];
                                                foreach($pf['sub_ingredients'] ?? [] as $sub) {
                                                    $isSubConflict = is_array($sub['conflict'] ?? null);
                                                    $isSubResolved = $isSubConflict && ($sub['conflict']['is_resolved'] ?? false);
                                                    
                                                    if ($isSubResolved) {
                                                        $changes[] = "🔄 {$sub['name']} ➡ " . ($sub['conflict']['replacement']['name'] ?? 'Інше');
                                                    } elseif ($isSubConflict) {
                                                        $changes[] = "❌ Без {$sub['name']}";
                                                    }
                                                }
                                            @endphp

                                            {{-- ВИВОДИМО ПФ ТІЛЬКИ ЯКЩО Є ЗМІНИ В ЦЬОМУ КОНКРЕТНОМУ ПФ --}}
                                            @if(!empty($changes))
                                                @php
                                                    $changeStr = implode(', ', $changes);
                                                @endphp

                                                <div class="pf-card border-orange-300">
                                                    <div class="pf-card-header bg-orange-100 text-orange-900 print:bg-gray-100 print:text-black">
                                                        <span>{{ $pf['name'] }} > <span class="text-blue-700 font-bold print:text-black">[{{ $changeStr }}]</span></span>
                                                    </div>
                                                    <table class="table-striped">
                                                        <thead>
                                                            <tr>
                                                                <th>Інгредієнт/ПФ</th>
                                                                <th style="width: 40px;">Брутто</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($pf['sub_ingredients'] as $item)
                                                                @php 
                                                                    $isSubConflict = is_array($item['conflict'] ?? null);
                                                                    $isSubResolved = $isSubConflict && ($item['conflict']['is_resolved'] ?? false);
                                                                @endphp
                                                                <tr>
                                                                    <td class="{{ ($item['type'] ?? '') === 'pf' ? 'font-bold' : '' }}">
                                                                        {{ ($item['type'] ?? '') === 'pf' ? '📦 ' : '' }}
                                                                        @if($isSubResolved)
                                                                            <span class="line-through text-gray-400">{{ $item['name'] }}</span><br>
                                                                            <span class="font-bold text-[9px] text-blue-600">🔄 На: {{ $item['conflict']['replacement']['name'] ?? 'Заміна' }}</span>
                                                                        @elseif($isSubConflict)
                                                                            <span class="line-through text-red-400">{{ $item['name'] }}</span><br>
                                                                            <span class="font-bold text-[9px] text-red-600">❌ БЕЗ</span>
                                                                        @else
                                                                            {{ $item['name'] }}
                                                                        @endif
                                                                    </td>
                                                                    <td class="text-center font-bold">
                                                                        {{ round($isSubResolved ? ($item['conflict']['replacement']['brutto'] ?? 0) : ($item['weight_brutto_sum'] ?? $item['weight_brutto'] ?? 0)) }}
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                            <tr class="sum-row">
                                                                <td class="text-right">Вихід ПФ:</td>
                                                                <td class="text-center">{{ round($pf['weight_output'] ?? 0) }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            @endif
                                        @endforeach
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endif

                @endforeach
            @endforeach
        @endif
    </div>
</body>
</html>