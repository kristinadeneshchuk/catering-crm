<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>План виробництва — {{ \Carbon\Carbon::parse($targetDate)->format('d.m.Y') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* БАЗОВІ СТИЛІ */
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { border: 1px solid #e5e7eb; padding: 4px 8px; text-align: left; }
        th { background-color: #f3f4f6; font-weight: bold; }
        
        /* 🔥 СПЕЦІАЛЬНІ СТИЛІ ТІЛЬКИ ДЛЯ ДРУКУ (ЩОБ ЕКОНОМИТИ ПАПІР) 🔥 */
        @media print {
            .no-print { display: none !important; }
            body { 
                background: white !important; 
                font-size: 11px !important; 
                padding: 0 !important; 
                margin: 0 !important; 
            }
            
            /* Забороняємо розривати один рецепт на дві сторінки */
            .avoid-break { 
                page-break-inside: avoid; 
                break-inside: avoid; 
            }

            /* Робимо таблиці максимально щільними */
            th, td { 
                padding: 2px 4px !important; 
                border-color: #cbd5e1 !important; 
                font-size: 10px !important;
            }
            
            /* Зменшуємо заголовки */
            h2 { font-size: 14px !important; margin-bottom: 4px !important; padding-bottom: 4px !important; }
            h3 { font-size: 12px !important; margin-bottom: 2px !important; }
            h4 { font-size: 11px !important; margin-bottom: 2px !important; margin-top: 4px !important; }
            
            /* Міняємо дизайн блоків, щоб не витрачати фарбу та місце */
            .meal-header { 
                background: #e2e8f0 !important; 
                color: #000 !important; 
                padding: 2px 6px !important; 
                font-size: 12px !important; 
                border: 1px solid #cbd5e1 !important;
                border-bottom: none !important;
            }
            .recipe-card {
                padding: 4px !important;
                margin-bottom: 6px !important;
                border: 1px solid #cbd5e1 !important;
                border-radius: 0 !important;
            }
        }
    </style>
</head>
<body class="bg-gray-100 p-4 sm:p-8 text-slate-800 font-sans print:p-0">

    @php
        // 🔄 РЕКУРСИВНА ФУНКЦІЯ ДЛЯ СТАНДАРТНИХ ПОРЦІЙ
        if (!function_exists('renderStandardIngredients')) {
            function renderStandardIngredients($ingredients, $level = 0) {
                $html = '';
                $padding = $level * 15; // Відступ для вкладеності
                $prefix = $level > 0 ? '↳ ' : '';

                foreach ($ingredients as $comp) {
                    if (($comp['type'] ?? '') === 'pf') {
                        $html .= '<tr class="bg-orange-50/50 print:bg-gray-100 border-b border-orange-100">';
                        $html .= '<td class="font-bold text-slate-700" style="padding-left: '.($padding + 8).'px;">📦 ' . $prefix . $comp['name'] . '</td>';
                        // Для ПФ беремо weight_output (вихід) та weight_brutto_sum
                        $html .= '<td class="font-bold text-slate-700">' . round($comp['weight_output'] ?? 0) . '</td>';
                        $html .= '<td class="font-bold text-slate-700">' . round($comp['weight_brutto_sum'] ?? 0) . '</td>';
                        $html .= '</tr>';
                        
                        if (!empty($comp['sub_ingredients'])) {
                            $html .= renderStandardIngredients($comp['sub_ingredients'], $level + 1);
                        }
                    } else {
                        $html .= '<tr class="border-b border-gray-100 text-slate-600">';
                        $html .= '<td style="padding-left: '.($padding + 8).'px;">🍎 ' . $prefix . $comp['name'] . '</td>';
                        // Для продуктів беремо netto та brutto
                        $html .= '<td>' . round($comp['weight_netto'] ?? 0) . '</td>';
                        $html .= '<td>' . round($comp['weight_brutto'] ?? 0) . '</td>';
                        $html .= '</tr>';
                    }
                }
                return $html;
            }
        }

        // 🔄 РЕКУРСИВНА ФУНКЦІЯ ДЛЯ КАСТОМНИХ КАРТОК (Тільки Брутто)
        if (!function_exists('renderCustomIngredients')) {
            function renderCustomIngredients($ingredients, $level = 0) {
                $html = '';
                $padding = $level * 10;
                $prefix = $level > 0 ? '↳ ' : '';

                foreach ($ingredients as $comp) {
                    if (($comp['type'] ?? '') === 'pf') {
                        $html .= '<tr class="bg-gray-50 print:bg-gray-100 border-b border-gray-200">';
                        $html .= '<td class="font-semibold" style="padding-left: '.($padding + 4).'px;">📦 ' . $prefix . $comp['name'] . '</td>';
                        $html .= '<td class="font-semibold">' . round($comp['weight_brutto_sum'] ?? 0) . '</td>';
                        $html .= '</tr>';
                        
                        if (!empty($comp['sub_ingredients'])) {
                            $html .= renderCustomIngredients($comp['sub_ingredients'], $level + 1);
                        }
                    } else {
                        $name = $comp['name'];
                        $brutto = round($comp['weight_brutto'] ?? 0);
                        $rowClass = '';

                        // Обробка замін
                        if (isset($comp['conflict']['is_resolved'])) {
                            if ($comp['conflict']['is_resolved']) {
                                $rowClass = 'bg-blue-50 text-blue-800 print:bg-gray-100';
                                $name = '<span class="line-through text-gray-400">'.$name.'</span><br><span class="font-bold text-[9px] text-blue-600">🔄 На: '.$comp['conflict']['replacement']['name'].'</span>';
                                $brutto = round($comp['conflict']['replacement']['brutto'] ?? $brutto);
                            } else {
                                $name .= '<br><span class="font-bold text-[9px] text-red-600">❌ Виключено</span>';
                            }
                        }

                        $html .= '<tr class="'.$rowClass.' border-b border-gray-100">';
                        $html .= '<td style="padding-left: '.($padding + 4).'px;">🍎 ' . $prefix . $name . '</td>';
                        $html .= '<td>' . $brutto . '</td>';
                        $html .= '</tr>';
                    }
                }
                return $html;
            }
        }
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
                {{-- Блок прийому їжі (Сніданок, Обід тощо) --}}
                <div class="mb-4 print:mb-2">
                    <div class="meal-header bg-slate-800 text-white px-4 py-2 rounded-t-lg font-bold uppercase text-lg">
                        {{ $mealName }}
                    </div>

                    @foreach($dishes as $dish)
                        {{-- Блок конкретного рецепту --}}
                        <div class="recipe-card avoid-break border border-t-0 border-slate-200 p-4 mb-4 rounded-b-lg">
                            <h3 class="text-lg font-bold text-slate-900 mb-2">{{ $dish['dish_name'] }}</h3>

                            {{-- СТАНДАРТНІ ПОРЦІЇ --}}
                            @if($dish['standard_count'] > 0)
                                <div class="mb-2">
                                    <div class="flex justify-between items-center bg-green-50 text-green-800 px-2 py-1 font-bold mb-1 border border-green-200 rounded print:border-gray-300 print:bg-white print:text-black">
                                        <span>Стандарт: {{ $dish['standard_count'] }} шт.</span>
                                        <span class="text-xs">Вихід/Нетто: {{ $dish['standard_total_netto'] }} г | Брутто: {{ $dish['standard_total_brutto'] }} г</span>
                                    </div>
                                    
                                    <table>
                                        <thead>
                                            <tr>
                                                <th class="w-1/2">Інгредієнт / ПФ</th>
                                                <th>Вихід/Нетто (г)</th>
                                                <th>Брутто (г)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {{-- Вкликаємо рекурсивну функцію для стандарту --}}
                                            {!! renderStandardIngredients($dish['standard_structure']) !!}
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            {{-- ІНДИВІДУАЛЬНІ ЗАМОВЛЕННЯ --}}
                            @if(count($dish['custom_cards']) > 0)
                                <div>
                                    <h4 class="font-bold text-red-600 mb-1 uppercase text-xs border-b border-red-100 pb-0.5 print:text-black print:border-gray-300">
                                        ⚠️ Індивідуальні замовлення
                                    </h4>
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2 print:gap-1">
                                        @foreach($dish['custom_cards'] as $card)
                                            <div class="border border-red-200 bg-red-50/30 p-2 rounded print:border-gray-300 print:bg-white avoid-break">
                                                <div class="font-black text-slate-800 mb-0.5 flex justify-between text-xs">
                                                    <span>{{ $card['client_name'] }}</span>
                                                    @if($card['dish_excluded'] && !$card['dish_replacement'])
                                                        <span class="bg-red-500 text-white text-[9px] px-1 rounded uppercase print:border print:border-black print:text-black print:bg-white">Не готувати</span>
                                                    @endif
                                                </div>
                                                
                                                @if($card['comment'])
                                                    <div class="text-[10px] text-red-600 font-bold mb-1 print:text-black">Коментар: {{ $card['comment'] }}</div>
                                                @endif

                                                @if($card['dish_replacement'])
                                                    <div class="text-[10px] text-blue-600 font-bold mb-1 border-l-2 border-blue-600 pl-1 print:text-black print:border-gray-500">
                                                        🔄 Заміна на: {{ $card['dish_replacement'] }}
                                                    </div>
                                                @endif

                                                @if(!empty($card['components']))
                                                    <table class="text-[9px] mb-0 mt-1">
                                                        <thead>
                                                            <tr class="bg-white">
                                                                <th>Склад</th>
                                                                <th class="w-12">Брутто (г)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            {{-- Вкликаємо рекурсивну функцію для кастому --}}
                                                            {!! renderCustomIngredients($card['components']) !!}
                                                        </tbody>
                                                    </table>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    </div>
</body>
</html>