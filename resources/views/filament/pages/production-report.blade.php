<x-filament-panels::page>
    <style>
        @media print { 
            .fi-sidebar, .fi-topbar, .fi-header, .no-print, .fi-header-actions, .fi-footer { display: none !important; } 
            .fi-main-ctn { padding: 0 !important; margin: 0 !important; width: 100% !important; }
            .fi-main { padding: 0 !important; }
            body { background: white !important; color: black !important; }
            .dish-block { page-break-inside: avoid; border-bottom: 2px solid #e2e8f0 !important; }
        }
    </style>

    @php
        // 🔄 РЕКУРСИВНА ФУНКЦІЯ ДЛЯ СТАНДАРТНИХ ПОРЦІЙ
        if (!function_exists('renderStandardList')) {
            function renderStandardList($ingredients, $level = 0) {
                $pfs = array_filter($ingredients, fn($c) => ($c['type'] ?? '') === 'pf');
                $prods = array_filter($ingredients, fn($c) => ($c['type'] ?? '') !== 'pf');
                $sortedIngredients = array_merge($pfs, $prods);

                $html = '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px 24px; width: 100%;">';
                
                foreach ($sortedIngredients as $comp) {
                    if (($comp['type'] ?? '') === 'pf') {
                        $html .= '<div style="grid-column: span 2; background-color: '.($level==0 ? 'white' : '#f8fafc').'; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-top: 4px; margin-bottom: 4px;">';
                        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; font-weight: 800; color: #475569; font-size: 13px; margin-bottom: 10px;">';
                        $html .= '<span>НФ: ' . $comp['name'] . ' (Вихід: ' . round($comp['weight_output'] ?? 0) . 'г)</span>';
                        $html .= '</div>';
                        
                        if (!empty($comp['sub_ingredients'])) {
                            $html .= renderStandardList($comp['sub_ingredients'], $level + 1);
                        }
                        $html .= '</div>';
                    } else {
                        $isRoot = $level === 0;
                        $nameColor = $isRoot ? '#0f172a' : '#64748b'; 
                        $weightColor = $isRoot ? '#0f172a' : '#475569';
                        $fontSize = $isRoot ? '14px' : '12px';
                        $fontWeight = $isRoot ? '700' : '400';
                        $padding = $isRoot ? 'padding: 8px 0;' : 'padding: 4px 0;';
                        $border = $isRoot ? 'border-bottom: 1px solid #e2e8f0;' : 'border-bottom: 1px dashed rgba(0,0,0,0.05);';

                        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; '.$padding.' '.$border.'">';
                        $html .= '<span style="color: '.$nameColor.'; font-weight: '.$fontWeight.'; font-size: '.$fontSize.';">' . $comp['name'] . '</span>';
                        $html .= '<span style="color: '.$weightColor.'; font-weight: 700; font-size: '.$fontSize.';">' . round($comp['weight_brutto'] ?? 0) . ' г</span>';
                        $html .= '</div>';
                    }
                }
                $html .= '</div>';
                return $html;
            }
        }

        // 🔄 РЕКУРСИВНА ФУНКЦІЯ ДЛЯ ІНДИВІДУАЛЬНИХ КАРТОК
        if (!function_exists('pfHasConflicts')) {
            function pfHasConflicts(array $ingredients): bool {
                foreach ($ingredients as $comp) {
                    if (!empty($comp['conflict'])) return true;
                    if (($comp['type'] ?? '') === 'pf' && !empty($comp['sub_ingredients'])) {
                        if (pfHasConflicts($comp['sub_ingredients'])) return true;
                    }
                }
                return false;
            }
        }

        if (!function_exists('renderCustomList')) {
            function renderCustomList($ingredients, $component, $dishRowId, $cardOrderId, $level = 0) {
                $pfs = array_filter($ingredients, fn($c) => ($c['type'] ?? '') === 'pf');
                $prods = array_filter($ingredients, fn($c) => ($c['type'] ?? '') !== 'pf');
                $sortedIngredients = array_merge($pfs, $prods);

                $html = '<div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">';

                foreach ($sortedIngredients as $comp) {
                    if (($comp['type'] ?? '') === 'pf') {
                        // Пропускаємо НФ без жодного конфлікту
                        if (empty($comp['sub_ingredients']) || !pfHasConflicts($comp['sub_ingredients'])) {
                            continue;
                        }

                        $pfOutput = round($comp['weight_output'] ?? 0);

                        $html .= '<div style="background-color:rgba(254,242,242,0.7); border:1px solid #fca5a5; border-radius:6px; padding:6px 8px; margin-top:2px;">';
                        $html .= '<div style="font-weight:800; font-size:10px; color:#94a3b8; margin-bottom:4px;">НФ: ' . $comp['name'] . ' (Вихід: ' . $pfOutput . 'г)</div>';
                        $html .= '<div style="border-left:2px solid #fca5a5; padding-left:8px; margin-left:2px;">';
                        $html .= renderCustomList($comp['sub_ingredients'], $component, $dishRowId, $cardOrderId, $level + 1);
                        $html .= '</div>';
                        $html .= '</div>';
                    } else {
                        $hasConflict = isset($comp['conflict']);

                        // Пропускаємо звичайні інгредієнти без конфліктів
                        if (!$hasConflict) continue;

                        $name = $comp['name'];
                        $brutto = round($comp['weight_brutto'] ?? 0);
                        $isResolved = isset($comp['conflict']['is_resolved']) && $comp['conflict']['is_resolved'];
                        
                        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; border-bottom: 1px dashed rgba(0,0,0,0.05); padding-bottom: 4px;">';
                        $html .= '<div style="display: flex; flex-direction: column; gap: 1px;">';

                        // SVG-іконки (замість емодзі)
                        $svgX = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" style="width:10px;height:10px;display:inline-block;vertical-align:middle;flex-shrink:0;"><path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm2.78-4.22a.75.75 0 0 1-1.06 0L8 9.06l-1.72 1.72a.75.75 0 1 1-1.06-1.06L6.94 8 5.22 6.28a.75.75 0 0 1 1.06-1.06L8 6.94l1.72-1.72a.75.75 0 1 1 1.06 1.06L9.06 8l1.72 1.72a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd"/></svg>';
                        $svgWarning = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" style="width:10px;height:10px;display:inline-block;vertical-align:middle;flex-shrink:0;"><path fill-rule="evenodd" d="M6.701 2.25c.577-1 2.02-1 2.598 0l5.196 9a1.5 1.5 0 0 1-1.299 2.25H2.804a1.5 1.5 0 0 1-1.3-2.25l5.197-9ZM8 4a.75.75 0 0 1 .75.75v3a.75.75 0 0 1-1.5 0v-3A.75.75 0 0 1 8 4Zm0 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>';
                        $svgArrow = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" style="width:10px;height:10px;display:inline-block;vertical-align:middle;flex-shrink:0;"><path fill-rule="evenodd" d="M2 8a.75.75 0 0 1 .75-.75h8.69L8.22 4.03a.75.75 0 0 1 1.06-1.06l4.5 4.5a.75.75 0 0 1 0 1.06l-4.5 4.5a.75.75 0 0 1-1.06-1.06l3.22-3.22H2.75A.75.75 0 0 1 2 8Z" clip-rule="evenodd"/></svg>';

                        $isForceApproved = $comp['conflict']['is_force_approved'] ?? false;

                        if ($hasConflict) {
                            $html .= '<span style="color: #94a3b8; text-decoration: line-through;">' . $name . '</span>';
                            if ($isForceApproved) {
                                $html .= '<span style="display:inline-flex; align-items:center; gap:3px; background:#dcfce7; color:#166534; border:1px solid #86efac; border-radius:4px; font-size:9px; font-weight:900; padding:2px 5px;">✓ Одобрено примусово</span>';
                            } elseif ($isResolved) {
                                $html .= '<span style="display:inline-flex; align-items:center; gap:3px; color:#166534; font-weight:700; font-size:10px;">' . $svgArrow . 'На: ' . $comp['conflict']['replacement']['name'] . '</span>';
                                $brutto = round($comp['conflict']['replacement']['brutto'] ?? $brutto);
                            } else {
                                $html .= '<span style="display:inline-flex; align-items:center; gap:3px; color:#dc2626; font-weight:700; font-size:10px;">' . $svgX . 'Виключено</span>';
                                if (!empty($comp['conflict']['allergen'])) {
                                    $allergenName = htmlspecialchars($comp['conflict']['allergen'], ENT_QUOTES);
                                    $html .= '<span style="display:inline-flex; align-items:center; gap:3px; background:#fef3c7; color:#92400e; border:1px solid #fbbf24; border-radius:4px; font-size:9px; font-weight:900; padding:2px 5px; margin-top:2px;">' . $svgWarning . 'АЛЕРГІЯ: ' . $allergenName . '</span>';
                                }
                            }
                        }
                        $html .= '</div>';
                        
                        $html .= '<div style="display: flex; align-items: center; gap: 6px;">';
                        $html .= '<span style="font-weight: 700; color: #0f172a;">' . $brutto . ' г</span>';
                        
                        // 🔥 ВИПРАВЛЕНО: Кнопки екшенів доступні на будь-якому рівні вкладеності
                        if ($hasConflict) {
                            $html .= '<div class="no-print" style="margin-left: 8px; display: flex; flex-direction: column; gap: 2px; align-items: flex-end;">';
                            if ($isForceApproved) {
                                $html .= '<button type="button" wire:click="mountAction(\'resetReplacement\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['conflict']['original_ing_id'] . ' })" style="color: #64748b; text-decoration: underline; font-size: 10px; cursor: pointer; border: none; background: transparent; padding: 0;">Скасувати</button>';
                            } elseif ($isResolved) {
                                $html .= '<button type="button" wire:click="mountAction(\'resetReplacement\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['conflict']['original_ing_id'] . ' })" style="color: #64748b; text-decoration: underline; font-size: 10px; cursor: pointer; border: none; background: transparent; padding: 0;">Скасувати</button>';
                            } else {
                                $html .= '<button type="button" wire:click="mountAction(\'replaceIngredient\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['product_id'] . ' })" style="color: #ea580c; text-decoration: underline; font-size: 10px; cursor: pointer; border: none; background: transparent; padding: 0;">→ Замінити</button>';
                                $html .= '<button type="button" wire:click="mountAction(\'forceApproveIngredient\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['product_id'] . ' })" style="color: #16a34a; font-size: 10px; cursor: pointer; border: 1px solid #16a34a; background: #f0fdf4; border-radius: 3px; padding: 1px 5px; font-weight: 700;">✓ Одобрити</button>';
                            }
                            $html .= '</div>';
                        }
                        
                        $html .= '</div></div>';
                    }
                }
                $html .= '</div>';
                return $html;
            }
        }
    @endphp

    <div class="no-print" style="margin-bottom: 15px;">
        <form wire:submit.prevent="calculate">
            {{ $this->form }}
        </form>
    </div>

    {{-- БЛОК ОЧІКУВАНОГО СПИСАННЯ --}}
    @php
        $summaryIngredients = [];
        $collectSummary = function($components) use (&$summaryIngredients, &$collectSummary) {
            foreach ($components as $comp) {
                if ($comp['type'] === 'product') {
                    $name = $comp['name'];
                    $summaryIngredients[$name] = ($summaryIngredients[$name] ?? 0) + ($comp['weight_brutto'] ?? 0);
                } elseif ($comp['type'] === 'pf' && isset($comp['sub_ingredients'])) {
                    $collectSummary($comp['sub_ingredients']);
                }
            }
        };

        foreach($reportData as $mealGroup) {
            foreach($mealGroup as $dishRow) {
                $collectSummary($dishRow['standard_structure']);
                foreach ($dishRow['custom_cards'] as $card) {
                    if (!$card['dish_excluded'] || isset($card['dish_replacement'])) {
                        $collectSummary($card['components']);
                    }
                }
            }
        }
        ksort($summaryIngredients);
    @endphp

    @if(!empty($summaryIngredients))
    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px; margin-bottom: 20px;">
        <h3 style="font-weight: 700; color: #1e293b; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; font-size: 14px;">
            <x-filament::icon icon="heroicon-m-calculator" class="w-4 h-4 text-orange-500" />
            Очікуване списання (Брутто)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 6px;">
            @foreach($summaryIngredients as $name => $weight)
                <div style="font-size: 11px; border-bottom: 1px solid #e2e8f0; padding-bottom: 2px; display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">{{ $name }}</span>
                    <span style="font-weight: 700; color: #0f172a;">{{ number_format($weight, 0, '.', ' ') }} г</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    @php
        // Збираємо всі унікальні коментарі з усіх страв — один раз на день
        $allDayComments = [];
        foreach($reportData as $mealGroup) {
            foreach($mealGroup as $dishRow) {
                foreach($dishRow['comment_clients'] ?? [] as $cc) {
                    $allDayComments[$cc['order_id']] = $cc;
                }
            }
        }
    @endphp

    {{-- КОМЕНТАРІ ДО ВИРОБНИЦТВА — один блок на весь день --}}
    @if(!empty($allDayComments))
        <div style="margin-bottom: 15px; border-left: 4px solid #f59e0b; background-color: #fffbeb; padding: 10px 14px; border-radius: 0 8px 8px 0;">
            <div style="font-weight: 800; font-size: 13px; color: #92400e; margin-bottom: 8px;">КОМЕНТАРІ ДО ВИРОБНИЦТВА:</div>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 4px;">
                @foreach($allDayComments as $cc)
                    <div style="font-size: 12px; padding: 3px 0; border-bottom: 1px dashed #fde68a; color: #78350f;">
                        <span style="font-weight: 700; color: #1c1917;">{{ $cc['client_name'] }}</span>
                        <span> — {{ $cc['comment'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- КНОПКИ МАСОВИХ ДІЙ --}}
    @if(!empty($reportData))
    <div class="no-print" style="margin-bottom: 12px; display: flex; justify-content: flex-end; gap: 8px;">
        {{ ($this->applyBundleAction)([]) }}
        {{ ($this->massReplaceIngredientAction)([]) }}
    </div>
    @endif

    <div style="display: flex; flex-direction: column; gap: 15px; color: #0f172a !important;">
        @forelse($reportData as $mealName => $dishes)
            <div class="meal-group" style="background-color: white; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                <div style="background-color: #fff7ed; padding: 8px 15px; border-bottom: 1px solid #ffedd5;">
                    <h2 style="color: #ea580c !important; font-size: 16px; font-weight: 800; text-transform: uppercase; margin: 0;">{{ $mealName }}</h2>
                </div>
                <div style="padding: 15px;">
                    @foreach($dishes as $dishRow)
                        @php
                            $customCount   = count($dishRow['custom_cards']);
                            $excludedCount = collect($dishRow['custom_cards'])->where('dish_excluded', true)->where('dish_replacement', null)->count();
                            $totalPortions = $dishRow['standard_count'] + $customCount - $excludedCount;
                        @endphp
                        <div class="dish-block" style="border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 15px;">
                            <div style="display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px;">
                                <h3 style="font-size: 18px; font-weight: 800; margin: 0;">{{ $dishRow['dish_name'] }}</h3>
                                <span style="font-size: 13px; font-weight: 700; color: #64748b; white-space: nowrap;">
                                    Всього: <span style="color: #0f172a;">{{ $totalPortions }} порц.</span>
                                    @if($customCount > 0)
                                        <span style="color: #94a3b8; font-weight: 400;">({{ $dishRow['standard_count'] }} стандарт
                                        @if($customCount - $excludedCount > 0)+ {{ $customCount - $excludedCount }} індивід.@endif
                                        @if($excludedCount > 0)<span style="color: #ef4444;">  − {{ $excludedCount }} не їсть</span>@endif)</span>
                                    @endif
                                </span>
                            </div>

                            {{-- СТАНДАРТ (ЗАГАЛЬНИЙ КОТЕЛ) --}}
                            @if($dishRow['standard_count'] > 0)
                                <div style="margin-bottom: 15px; border-left: 4px solid #22c55e; background-color: #f0fdf4; padding: 10px; border-radius: 0 8px 8px 0;">
                                    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:15px;">
                                        <div style="font-weight: 800; font-size: 15px;">СТАНДАРТ: {{ $dishRow['standard_count'] }} порцій</div>
                                        <div style="font-weight: 800; font-size: 14px; color: #166534;">Б: {{ number_format($dishRow['standard_total_brutto'], 0) }}г | Н: {{ number_format($dishRow['standard_total_netto'], 0) }}г</div>
                                    </div>
                                    <div style="display: flex; flex-direction: column; gap: 8px;">
                                        {!! renderStandardList($dishRow['standard_structure']) !!}
                                    </div>
                                </div>
                            @endif

                            {{-- ІНДИВІДУАЛЬНІ КАРТКИ --}}
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px; align-items: start;">
                                @foreach($dishRow['custom_cards'] as $card)
                                    <div style="border: 2px solid {{ $card['dish_excluded'] && !$card['dish_replacement'] ? '#ef4444' : ($card['dish_excluded'] ? '#fecaca' : '#fde68a') }}; background-color: {{ $card['dish_excluded'] && !$card['dish_replacement'] ? '#fef2f2' : ($card['dish_excluded'] ? '#fef2f2' : '#fffbeb') }}; border-radius: 8px; padding: 10px;">

                                        {{-- Ім'я клієнта + кнопка --}}
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 4px;">
                                            <div style="font-weight: 800; font-size: 13px;">{{ $card['client_name'] }}</div>
                                            <div class="no-print">
                                                <button type="button" wire:click="mountAction('replaceDish', { order_id: {{ $card['order_id'] }}, dish_id: {{ $dishRow['dish_id'] }} })" style="background: white; border: 1px solid #ef4444; color: #ef4444; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; cursor: pointer;">
                                                    Замінити страву
                                                </button>
                                            </div>
                                        </div>

                                        @if($card['dish_excluded'] && !$card['dish_replacement'])
                                            {{-- БЛЮДО ПОВНІСТЮ ВИКЛЮЧЕНЕ --}}
                                            <div style="display: flex; align-items: center; gap: 8px; background-color: #fee2e2; border: 1px solid #fca5a5; border-radius: 6px; padding: 8px 10px;">
                                                <div style="width: 28px; height: 28px; background-color: #ef4444; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0;">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="white" style="width:14px;height:14px;"><path fill-rule="evenodd" d="M8 15A7 7 0 1 0 8 1a7 7 0 0 0 0 14Zm2.78-4.22a.75.75 0 0 1-1.06 0L8 9.06l-1.72 1.72a.75.75 0 1 1-1.06-1.06L6.94 8 5.22 6.28a.75.75 0 0 1 1.06-1.06L8 6.94l1.72-1.72a.75.75 0 1 1 1.06 1.06L9.06 8l1.72 1.72a.75.75 0 0 1 0 1.06Z" clip-rule="evenodd"/></svg>
                                                </div>
                                                <div style="flex: 1;">
                                                    <div style="font-size: 12px; font-weight: 900; color: #b91c1c;">НЕ ЇСТЬ ЦЮ СТРАВУ</div>
                                                    <div style="font-size: 10px; color: #ef4444; margin-top: 1px;">Потрібна заміна або пропустити</div>
                                                </div>
                                                <div class="no-print">
                                                    <button type="button" wire:click="mountAction('forceApproveDish', { order_id: {{ $card['order_id'] }}, dish_id: {{ $dishRow['dish_id'] }} })" style="color: #16a34a; font-size: 10px; cursor: pointer; border: 1px solid #16a34a; background: #f0fdf4; border-radius: 4px; padding: 3px 8px; font-weight: 700; white-space: nowrap;">✓ Одобрити</button>
                                                </div>
                                            </div>

                                        @elseif($card['dish_replacement'])
                                            {{-- ЗАМІНА СТРАВИ --}}
                                            <div style="background-color: #dcfce7; color: #166534; padding: 6px 8px; border-radius: 6px; font-weight: 800; font-size: 11px; margin-bottom: 6px;">
                                                Замінено на: {{ $card['dish_replacement'] }}
                                            </div>
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                {!! renderCustomList($card['components'], $this, $dishRow['dish_id'], $card['order_id']) !!}
                                            </div>

                                        @else
                                            {{-- ЗВИЧАЙНІ ВИКЛЮЧЕННЯ ІНГРЕДІЄНТІВ --}}
                                            <div style="display: flex; flex-direction: column; gap: 4px;">
                                                {!! renderCustomList($card['components'], $this, $dishRow['dish_id'], $card['order_id']) !!}
                                            </div>
                                        @endif

                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            <div style="text-align: center; padding: 30px; color: white; font-size: 15px; font-weight: 500;">
                Замовлень немає
            </div>
        @endforelse
    </div>
</x-filament-panels::page>