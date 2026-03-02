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
                        $html .= '<span>📦 ПФ: ' . $comp['name'] . ' (Вихід: ' . round($comp['weight_output'] ?? 0) . 'г)</span>';
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
        if (!function_exists('renderCustomList')) {
            function renderCustomList($ingredients, $component, $dishRowId, $cardOrderId, $level = 0) {
                $pfs = array_filter($ingredients, fn($c) => ($c['type'] ?? '') === 'pf');
                $prods = array_filter($ingredients, fn($c) => ($c['type'] ?? '') !== 'pf');
                $sortedIngredients = array_merge($pfs, $prods);

                $html = '<div style="display: flex; flex-direction: column; gap: 4px; width: 100%;">';
                
                foreach ($sortedIngredients as $comp) {
                    if (($comp['type'] ?? '') === 'pf') {
                        $html .= '<div style="background-color: rgba(255,255,255,0.6); border: 1px solid #e2e8f0; border-radius: 6px; padding: 8px; margin-top: 2px;">';
                        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; font-weight: 800; font-size: 11px; color: #475569; margin-bottom: 6px;">';
                        $html .= '<span>📦 ' . $comp['name'] . ' (Вихід: ' . round($comp['weight_output'] ?? 0) . 'г)</span>';
                        $html .= '<span>' . round($comp['weight_brutto'] ?? $comp['weight_brutto_sum'] ?? 0) . ' г</span>';
                        $html .= '</div>';
                        
                        if (!empty($comp['sub_ingredients'])) {
                            $html .= '<div style="border-left: 2px solid #e2e8f0; padding-left: 8px; margin-left: 4px;">';
                            $html .= renderCustomList($comp['sub_ingredients'], $component, $dishRowId, $cardOrderId, $level + 1);
                            $html .= '</div>';
                        }
                        $html .= '</div>';
                    } else {
                        $name = $comp['name'];
                        $brutto = round($comp['weight_brutto'] ?? 0);
                        $isResolved = isset($comp['conflict']['is_resolved']) && $comp['conflict']['is_resolved'];
                        $hasConflict = isset($comp['conflict']);
                        
                        $color = $level === 0 ? '#0f172a' : '#64748b';
                        $weight = $level === 0 ? '600' : '400';
                        
                        $html .= '<div style="display: flex; justify-content: space-between; align-items: center; font-size: 12px; border-bottom: 1px dashed rgba(0,0,0,0.05); padding-bottom: 4px;">';
                        $html .= '<div style="display: flex; flex-direction: column; gap: 1px;">';
                        
                        if ($hasConflict) {
                            $html .= '<span style="color: #94a3b8; text-decoration: line-through;">' . $name . '</span>';
                            if ($isResolved) {
                                $html .= '<span style="color: #166534; font-weight: 700; font-size: 10px;">🔄 На: ' . $comp['conflict']['replacement']['name'] . '</span>';
                                $brutto = round($comp['conflict']['replacement']['brutto'] ?? $brutto);
                            } else {
                                $html .= '<span style="color: #dc2626; font-weight: 700; font-size: 10px;">❌ Виключено</span>';
                            }
                        } else {
                            $html .= '<span style="color: '.$color.'; font-weight: '.$weight.';">' . $name . '</span>';
                        }
                        $html .= '</div>';
                        
                        $html .= '<div style="display: flex; align-items: center; gap: 6px;">';
                        $html .= '<span style="font-weight: 700; color: #0f172a;">' . $brutto . ' г</span>';
                        
                        // 🔥 ВИПРАВЛЕНО: Кнопки екшенів доступні на будь-якому рівні вкладеності
                        if ($hasConflict) { 
                            $html .= '<div class="no-print" style="margin-left: 8px;">';
                            if ($isResolved) {
                                $html .= '<button type="button" wire:click="mountAction(\'resetReplacement\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['conflict']['original_ing_id'] . ' })" style="color: #64748b; text-decoration: underline; font-size: 10px; cursor: pointer; border: none; background: transparent; padding: 0;">Скасувати</button>';
                            } else {
                                $html .= '<button type="button" wire:click="mountAction(\'replaceIngredient\', { order_id: ' . $cardOrderId . ', dish_id: ' . $dishRowId . ', product_id: ' . $comp['product_id'] . ' })" style="color: #ea580c; text-decoration: underline; font-size: 10px; cursor: pointer; border: none; background: transparent; padding: 0;">🔄 Замінити</button>';
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

    <div style="display: flex; flex-direction: column; gap: 15px; color: #0f172a !important;">
        @forelse($reportData as $mealName => $dishes)
            <div class="meal-group" style="background-color: white; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;">
                <div style="background-color: #fff7ed; padding: 8px 15px; border-bottom: 1px solid #ffedd5;">
                    <h2 style="color: #ea580c !important; font-size: 16px; font-weight: 800; text-transform: uppercase; margin: 0;">{{ $mealName }}</h2>
                </div>
                <div style="padding: 15px;">
                    @foreach($dishes as $dishRow)
                        <div class="dish-block" style="border-bottom: 2px solid #f1f5f9; padding-bottom: 15px; margin-bottom: 15px;">
                            <h3 style="font-size: 18px; font-weight: 800; margin-bottom: 10px;">{{ $dishRow['dish_name'] }}</h3>

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
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 10px;">
                                @foreach($dishRow['custom_cards'] as $card)
                                    <div style="border: 1px solid {{ $card['dish_excluded'] ? '#fecaca' : '#fde68a' }}; background-color: {{ $card['dish_excluded'] ? '#fef2f2' : '#fffbeb' }}; border-radius: 8px; padding: 10px;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 6px; border-bottom: 1px solid rgba(0,0,0,0.05); padding-bottom: 4px;">
                                            <div style="font-weight: 800; font-size: 13px;">{{ $card['client_name'] }}</div>
                                            
                                            <div class="no-print">
                                                <button type="button" wire:click="mountAction('replaceDish', { order_id: {{ $card['order_id'] }}, dish_id: {{ $dishRow['dish_id'] }} })" style="background: white; border: 1px solid #ef4444; color: #ef4444; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 700; cursor: pointer;">
                                                    🔄 Замінити
                                                </button>
                                            </div>
                                        </div>

                                        @if($card['dish_replacement'])
                                            <div style="background-color: #dcfce7; color: #166534; padding: 4px; border-radius: 4px; text-align: center; font-weight: 800; font-size: 11px; margin-bottom: 6px;">
                                                🔄 ЗАМІНА: {{ $card['dish_replacement'] }}
                                            </div>
                                        @endif

                                        <div style="display: flex; flex-direction: column; gap: 4px;">
                                            {!! renderCustomList($card['components'], $this, $dishRow['dish_id'], $card['order_id']) !!}
                                        </div>
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