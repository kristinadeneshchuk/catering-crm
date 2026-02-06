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

    <div class="no-print" style="margin-bottom: 20px;">
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
    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 30px;">
        <h3 style="font-weight: 800; color: #1e293b; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; font-size: 18px;">
            <x-filament::icon icon="heroicon-m-calculator" class="w-5 h-5 text-orange-500" />
            Очікуване списання на сьогодні (Брутто)
        </h3>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px;">
            @foreach($summaryIngredients as $name => $weight)
                <div style="font-size: 13px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; display: flex; justify-content: space-between;">
                    <span style="color: #64748b;">{{ $name }}</span>
                    <span style="font-weight: 700; color: #0f172a;">{{ number_format($weight, 0, '.', ' ') }} г</span>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div style="display: flex; flex-direction: column; gap: 30px; color: #0f172a !important;">
        @forelse($reportData as $mealName => $dishes)
            <div class="meal-group" style="background-color: white; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1);">
                <div style="background-color: #fff7ed; padding: 15px 25px; border-bottom: 2px solid #ffedd5;">
                    <h2 style="color: #ea580c !important; font-size: 24px; font-weight: 900; text-transform: uppercase; margin: 0;">{{ $mealName }}</h2>
                </div>
                <div style="padding: 25px;">
                    @foreach($dishes as $dishRow)
                        <div class="dish-block" style="border-bottom: 4px solid #f1f5f9; padding-bottom: 30px; margin-bottom: 30px;">
                            <h3 style="font-size: 26px; font-weight: 900; margin-bottom: 20px;">{{ $dishRow['dish_name'] }}</h3>

                            {{-- СТАНДАРТ (ЗАГАЛЬНИЙ КОТЕЛ) --}}
                            @if($dishRow['standard_count'] > 0)
                                <div style="margin-bottom: 25px; border-left: 5px solid #22c55e; background-color: #f0fdf4; padding: 15px; border-radius: 0 12px 12px 0;">
                                    <div style="display:flex; justify-content: space-between; align-items:center; margin-bottom:10px;">
                                        <div style="font-weight: 800;">СТАНДАРТ: {{ $dishRow['standard_count'] }} порцій</div>
                                        <div style="font-weight: 800; color: #166534;">Б: {{ number_format($dishRow['standard_total_brutto'], 0) }}г | Н: {{ number_format($dishRow['standard_total_netto'], 0) }}г</div>
                                    </div>
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        @foreach($dishRow['standard_structure'] as $comp)
                                            @if($comp['type'] === 'product')
                                                <div style="display: flex; justify-content: space-between; border-bottom: 1px dashed #cbd5e1;">
                                                    <span style="font-weight: 600;">{{ $comp['name'] }}</span>
                                                    <span style="font-weight: 700;">{{ round($comp['weight_brutto'] ?? 0) }} г</span>
                                                </div>
                                            @else
                                                {{-- Напівфабрикат у стандарті --}}
                                                <div style="grid-column: span 2; background-color: white; border: 1px solid #e2e8f0; padding: 10px; border-radius: 8px;">
                                                    <div style="font-weight: 900; color: #475569; font-size: 13px; margin-bottom: 5px;">📦 ПФ: {{ $comp['name'] }} (Вихід: {{ $comp['weight_output'] }}г)</div>
                                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding-left: 10px;">
                                                        @foreach($comp['sub_ingredients'] as $sub)
                                                            <div style="display: flex; justify-content: space-between; font-size: 12px;">
                                                                <span style="color: #64748b;">{{ $sub['name'] }}</span>
                                                                <span>{{ round($sub['weight_brutto'] ?? 0) }} г</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- ІНДИВІДУАЛЬНІ КАРТКИ --}}
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                                @foreach($dishRow['custom_cards'] as $card)
                                    <div style="border: 2px solid {{ $card['dish_excluded'] ? '#fecaca' : '#fde68a' }}; background-color: {{ $card['dish_excluded'] ? '#fef2f2' : '#fffbeb' }}; border-radius: 12px; padding: 15px;">
                                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,0.1); padding-bottom: 5px;">
                                            <div style="font-weight: 900; font-size: 16px;">{{ $card['client_name'] }}</div>
                                            <div class="no-print">
                                                @if($card['dish_excluded'] || $card['dish_replacement'])
                                                    {{ ($this->replaceDishAction)(['order_id' => $card['order_id'], 'dish_id' => $dishRow['dish_id']]) }}
                                                @endif
                                            </div>
                                        </div>

                                        @if($card['dish_replacement'])
                                            <div style="background-color: #dcfce7; color: #166534; padding: 8px; border-radius: 8px; text-align: center; font-weight: 900; margin-bottom: 10px;">
                                                🔄 ЗАМІНА СТРАВИ: {{ $card['dish_replacement'] }}
                                            </div>
                                        @endif

                                        <div style="display: flex; flex-direction: column; gap: 8px;">
                                            @foreach($card['components'] as $comp)
                                                @if($comp['type'] === 'product')
                                                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 13px;">
                                                        <div style="display: flex; align-items: center; gap: 5px;">
                                                            <span style="{{ isset($comp['conflict']) ? 'color: #94a3b8; text-decoration: line-through;' : '' }}">{{ $comp['name'] }}</span>
                                                            <div class="no-print">
                                                                @if(isset($comp['conflict']))
                                                                    @if($comp['conflict']['is_resolved'])
                                                                        {{ ($this->resetReplacementAction)(['order_id' => $card['order_id'], 'dish_id' => $dishRow['dish_id'], 'product_id' => $comp['conflict']['original_ing_id']]) }}
                                                                        <span style="color: #166534; font-weight: 800;">➜ {{ $comp['conflict']['replacement']['name'] }}</span>
                                                                    @else
                                                                        {{ ($this->replaceIngredientAction)(['order_id' => $card['order_id'], 'dish_id' => $dishRow['dish_id'], 'product_id' => $comp['product_id']]) }}
                                                                    @endif
                                                                @endif
                                                            </div>
                                                        </div>
                                                        <span style="font-weight: 700;">{{ round($comp['conflict']['replacement']['brutto'] ?? $comp['weight_brutto'] ?? 0) }} г</span>
                                                    </div>
                                                @else
                                                    {{-- Напівфабрикат у картці клієнта --}}
                                                    <div style="background-color: rgba(255,255,255,0.5); border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px;">
                                                        <div style="font-weight: 800; font-size: 11px; color: #64748b; margin-bottom: 5px;">📦 ПФ: {{ $comp['name'] }} (Вихід: {{ $comp['weight_output'] }}г)</div>
                                                        <div style="display: flex; flex-direction: column; gap: 5px;">
                                                            @foreach($comp['sub_ingredients'] as $sub)
                                                                <div style="display: flex; justify-content: space-between; font-size: 12px; padding-left: 5px;">
                                                                    <div style="display: flex; align-items: center; gap: 5px;">
                                                                        <span style="{{ isset($sub['conflict']) ? 'color: #94a3b8; text-decoration: line-through;' : '' }}">{{ $sub['name'] }}</span>
                                                                        <div class="no-print">
                                                                            @if(isset($sub['conflict']))
                                                                                @if($sub['conflict']['is_resolved'])
                                                                                    {{ ($this->resetReplacementAction)(['order_id' => $card['order_id'], 'dish_id' => $dishRow['dish_id'], 'product_id' => $sub['conflict']['original_ing_id']]) }}
                                                                                    <span style="color: #166534; font-weight: 800;">➜ {{ $sub['conflict']['replacement']['name'] }}</span>
                                                                                @else
                                                                                    {{ ($this->replaceIngredientAction)(['order_id' => $card['order_id'], 'dish_id' => $dishRow['dish_id'], 'product_id' => $sub['product_id']]) }}
                                                                                @endif
                                                                            @endif
                                                                        </div>
                                                                    </div>
                                                                    <span>{{ round($sub['conflict']['replacement']['brutto'] ?? $sub['weight_brutto'] ?? 0) }} г</span>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @empty
            {{-- Додали color: white та трохи збільшили шрифт --}}
            <div style="text-align: center; padding: 50px; color: white; font-size: 18px; font-weight: 500;">
                Замовлень немає
            </div>
        @endforelse
    </div>
</x-filament-panels::page>