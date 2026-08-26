<x-filament-panels::page>
    <div id="packaging-list-master-container">
        <style>
           /* Стилі для відображення в адмінці */
            .matrix-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; background: white; color: black; font-size: 13px; border: 1px solid #e5e7eb; }
            .matrix-table th, .matrix-table td { border: 1px solid #e5e7eb; padding: 6px 4px; text-align: center; }

            .header-top { background: #4ade80; color: #064e3b; font-weight: 900; text-transform: uppercase; }
            .header-kcal { background: #f3f4f6; font-weight: 700; font-size: 11px; }
            .row-label { text-align: left; font-weight: 700; background: #f9fafb; padding-left: 10px; width: 220px; }
            .row-count { background: #dcfce7; color: #14532d; font-weight: 900; font-size: 15px; }

            .meal-badge { background: #ea580c; color: white; padding: 8px 20px; border-radius: 8px; font-size: 18px; font-weight: 900; text-transform: uppercase; display: inline-block; margin-bottom: 12px; }
            .replacements-container { font-size: 13px; color: #7f1d1d; background: #fee2e2; padding: 12px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #fca5a5; }
        </style>

        {{-- ПАНЕЛЬ КЕРУВАННЯ --}}
        <div style="background: #18181b; padding: 20px; border-radius: 15px; margin-bottom: 25px; border: 1px solid #27272a;">
            <form wire:submit.prevent="calculate">
                {{ $this->form }}
            </form>

            @if($this->debugMessage)
                <div style="color: #fbbf24; margin-top: 10px; font-weight: bold; font-size: 12px;">{{ $this->debugMessage }}</div>
            @endif

            {{-- Перемикач версій фасування. Обидві рахуються з тих самих
                 техкарт, стара лишається робочою, поки друга не обкатана. --}}
            <div style="display:flex; gap:8px; margin-top:16px; align-items:center; flex-wrap:wrap;">
                <span style="font-size:11px; color:#71717a; text-transform:uppercase; letter-spacing:.5px; font-weight:800;">Версія фасування</span>

                <button type="button" wire:click="switchVersion('v1')"
                        style="padding:6px 14px; font-size:12px; font-weight:800; border-radius:8px; cursor:pointer; border:1px solid {{ $this->version === 'v1' ? '#fbbf24' : '#3f3f46' }}; background:{{ $this->version === 'v1' ? 'rgba(251,191,36,.14)' : 'transparent' }}; color:{{ $this->version === 'v1' ? '#fbbf24' : '#a1a1aa' }};">
                    Чинна
                </button>

                <button type="button" wire:click="switchVersion('v2')"
                        style="padding:6px 14px; font-size:12px; font-weight:800; border-radius:8px; cursor:pointer; border:1px solid {{ $this->version === 'v2' ? '#22d3ee' : '#3f3f46' }}; background:{{ $this->version === 'v2' ? 'rgba(34,211,238,.14)' : 'transparent' }}; color:{{ $this->version === 'v2' ? '#22d3ee' : '#a1a1aa' }};">
                    Дві порції (нова)
                </button>

                @if($this->version === 'v2')
                    <span style="font-size:11px; color:#71717a;">
                        Розміри боксів — у довіднику прийомів, набір тарифів — у «Сітці порцій».
                    </span>
                @endif
            </div>
        </div>

        {{-- ПОПЕРЕДЖЕННЯ: ПЛАНИ БЕЗ МЕНЮ НА ЦЕЙ ДЕНЬ --}}
        @if(!empty($this->missingPlans))
            <div style="background:#7f1d1d; border:2px solid #f87171; border-radius:12px; padding:16px 20px; margin-bottom:20px; color:#fee2e2;">
                <div style="display:flex; align-items:center; gap:8px; font-weight:900; font-size:14px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px;">
                    ⚠️ Не вистачає меню для деяких планів
                </div>
                @foreach($this->missingPlans as $mp)
                    <div style="margin-bottom:6px; font-size:13px;">
                        <strong style="color:#fff;">{{ $mp['plan']->name }}</strong> — день №{{ $mp['day_number'] }} циклу не створено.
                        Зачеплено клієнтів: <strong>{{ $mp['orders_count'] }}</strong>
                        @if(!empty($mp['client_names']))
                            ({{ implode(', ', $mp['client_names']) }}@if($mp['orders_count'] > count($mp['client_names'])), …@endif)
                        @endif
                    </div>
                @endforeach
                <div style="font-size:11px; margin-top:8px; color:#fecaca;">
                    Створи день меню у «Циклічне меню» → відповідна вкладка плану → «Додати день».
                </div>
            </div>
        @endif

        {{-- ═══════════ ДРУГА ВЕРСІЯ: ДВІ ПОРЦІЇ НА СТРАВУ ═══════════ --}}
        @if($this->version === 'v2')
            @if(!empty($this->missingGrids))
                <div style="background:#7f1d1d; border:2px solid #f87171; border-radius:12px; padding:16px 20px; margin-bottom:20px; color:#fee2e2;">
                    <div style="font-weight:900; font-size:14px; margin-bottom:8px; text-transform:uppercase;">
                        ⚠️ Немає тарифу в сітці порцій
                    </div>
                    <div style="font-size:13px;">
                        @foreach($this->missingGrids as $kcal => $count)
                            <div>{{ $kcal }} ккал — {{ $count }} замовл.</div>
                        @endforeach
                    </div>
                    <div style="font-size:12px; opacity:.8; margin-top:8px;">
                        Ці замовлення в новий лист не потрапили. Додайте тариф у «Довідник → Сітка порцій».
                    </div>
                </div>
            @endif

            @forelse($this->reportV2 as $mealName => $rows)
                <div style="background:#18181b; border:1px solid #27272a; border-radius:14px; margin-bottom:18px; overflow:hidden;">
                    <div style="background:#22d3ee; color:#082f49; padding:10px 18px; font-weight:900; font-size:14px; text-transform:uppercase; letter-spacing:.5px;">
                        {{ $mealName }}
                    </div>

                    <table style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr style="background:#27272a; color:#a1a1aa; font-size:11px; text-transform:uppercase; letter-spacing:.4px;">
                                <th style="padding:8px 18px; text-align:left;">Страва</th>
                                <th style="padding:8px 12px; text-align:center; width:90px;">Порція</th>
                                <th style="padding:8px 12px; text-align:right; width:130px;">Вага</th>
                                <th style="padding:8px 12px; text-align:right; width:100px;">Бокс</th>
                                <th style="padding:8px 18px; text-align:right; width:110px;">Порцій</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $r)
                                <tr style="border-bottom:1px solid #27272a; color:#e4e4e7;">
                                    <td style="padding:10px 18px; font-weight:600;">{{ $r['dish'] }}</td>
                                    <td style="padding:10px 12px; text-align:center;">
                                        <span style="padding:2px 10px; border-radius:6px; font-size:11px; font-weight:900; background:{{ $r['is_large'] ? 'rgba(251,146,60,.16)' : 'rgba(148,163,184,.14)' }}; color:{{ $r['is_large'] ? '#fb923c' : '#94a3b8' }};">
                                            {{ $r['size'] }}
                                        </span>
                                    </td>
                                    <td style="padding:10px 12px; text-align:right; font-size:17px; font-weight:900; color:#fafafa;">
                                        {{ $r['weight'] }} <span style="font-size:12px; color:#71717a;">±{{ $r['tolerance'] }} г</span>
                                    </td>
                                    <td style="padding:10px 12px; text-align:right; color:#71717a; font-size:12px;">{{ $r['kcal_box'] }} ккал</td>
                                    <td style="padding:10px 18px; text-align:right; font-size:17px; font-weight:900; color:#22d3ee;">{{ $r['count'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @empty
                <div style="background:#18181b; border:1px solid #27272a; border-radius:14px; padding:40px; text-align:center; color:#71717a;">
                    Нічого не порахувалось. Перевірте, що в довіднику прийомів задані розміри боксів,
                    а в «Сітці порцій» є тарифи під калораж замовлень.
                </div>
            @endforelse
        @else

        {{-- ПОПЕРЕДНІЙ ПЕРЕГЛЯД --}}
        @if(count($report) > 0)
            <div class="bg-white p-6 rounded-xl border text-black">
                <h3 class="font-bold text-lg mb-6">Попередній перегляд на екрані:</h3>

                @if(!empty($clientComments))
                    <div class="replacements-container" style="margin-bottom: 24px; background:#fefce8; border:1px dashed #ca8a04;">
                        <div style="font-weight:900; text-transform:uppercase; margin-bottom:6px; font-size:11px; color:#92400e;">
                            Коментарі клієнтів:
                        </div>
                        @foreach($clientComments as $c)
                            <div style="margin-bottom:2px; font-weight:700; color:#78350f;">
                                • #{{ $c['id'] }} {{ $c['name'] }} ({{ $c['project'] }}, {{ $c['calories'] }} ккал): {{ $c['text'] }}
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- ОБХІД ПО ПЛАНАХ МЕНЮ --}}
                @foreach($report as $planId => $planBlock)
                @php
                    $cyclicTables       = array_filter($planBlock['tables'], fn($t) => empty($t['is_individual']));
                    $customClientTables = array_filter($planBlock['tables'], fn($t) => !empty($t['is_custom_client']));
                    // «Чисті» індивідуали — menu_type=individual, без прапорця is_custom_client.
                    $individualTables   = array_filter($planBlock['tables'], fn($t) => !empty($t['is_individual']) && empty($t['is_custom_client']));
                @endphp

                <div style="margin-top:18px; margin-bottom:12px; padding:10px 14px; background:#f5f3ff; border:2px solid #c4b5fd; border-radius:10px;">
                    <div style="font-size:9px; font-weight:800; color:#5b21b6; text-transform:uppercase; letter-spacing:0.5px;">План меню</div>
                    <div style="font-size:18px; font-weight:900; color:#1e1b4b;">{{ $planBlock['plan']->name }}</div>
                    <div style="font-size:11px; color:#5b21b6;">День циклу №{{ $planBlock['day_number'] }} з {{ $planBlock['plan']->cycle_days }}</div>
                </div>

                {{-- ЦИКЛІЧНІ СТРАВИ --}}
                @foreach($cyclicTables as $table)
                    <div class="meal-section">
                        <div class="meal-badge">{{ $table['meal'] }}: {{ $table['dish_name'] }}</div>
                        <table class="matrix-table">
                            <thead>
                                <tr class="header-top">
                                    <th rowspan="2" class="row-label">{{ $table['dish_name'] }}</th>
                                    <th colspan="{{ count($table['columns']) }}">Програма (ккал / Клієнт)</th>
                                    <th rowspan="2" style="font-size:13px;font-weight:900;width:80px;vertical-align:middle;">ЗАГАЛОМ</th>
                                </tr>
                                <tr class="header-kcal">
                                    @foreach($table['columns'] as $label => $info)
                                        <th style="font-size:15px;font-weight:900;">
                                            {{ $label }} <span style="font-size:11px;font-weight:500;opacity:.8;">({{ ($info['count'] ?? 0) + ($info['custom_count'] ?? 0) }})</span>
                                        </th>
                                    @endforeach
                                </tr>
                                <tr class="row-count">
                                    <td class="row-label">КІЛЬКІСТЬ ПОРЦІЙ</td>
                                    @foreach($table['columns'] as $info)
                                        <td>
                                            {{ ($info['count'] ?? 0) + ($info['custom_count'] ?? 0) }}
                                            @if(!empty($info['projects']))
                                                <div style="font-size:10px;font-weight:700;color:#111827;margin-top:2px;">
                                                    @foreach($info['projects'] as $p){{ $p['name'] }}: {{ $p['count'] }}@if(!empty($p['custom_count']))<span style="color:#dc2626;font-weight:800;"> ({{ $p['custom_count'] }})</span>@endif<br>@endforeach
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td style="font-size:16px;font-weight:900;">{{ collect($table['columns'])->sum(fn($c) => ($c['count'] ?? 0) + ($c['custom_count'] ?? 0)) }}</td>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($table['rows'] as $row)
                                    <tr>
                                        <td class="row-label">{{ $row['original_name'] }}</td>
                                        @php $rowTotal = 0; @endphp
                                        @foreach($table['columns'] as $colKey => $info)
                                            @php $cell=$row['cells'][$colKey]??0; $val=is_array($cell)?($cell['val']??0):$cell; $rowTotal+=$val*($info['count']??1); @endphp
                                            <td><span style="font-weight:800;">@if(($info['count'] ?? 0) > 0){{ $val }} г@else—@endif</span></td>
                                        @endforeach
                                        <td style="font-size:14px;font-weight:900;">{{ round($rowTotal) }} г</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @if(!empty($table['individual_notes']))
                            <div class="replacements-container">
                                <div style="font-weight:900;text-transform:uppercase;margin-bottom:5px;font-size:11px;">Індивідуальні заміни:</div>
                                @foreach($table['individual_notes'] as $note)
                                    <div style="margin-bottom:2px;font-weight:700;">• #{{ $note['id'] }} {{ $note['name'] }} ({{ $note['project'] }}, {{ $note['calories'] }} ккал): {{ $note['text'] }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- КАСТОМНІ КЛІЄНТИ — стандартні замовлення з інгредієнтними замінами / force-approved конфліктами --}}
                @if(!empty($customClientTables))
                    <div style="margin-top:24px; border-top:3px solid #ea580c; padding-top:16px;">
                        <div style="background:#ea580c; color:white; display:inline-block; padding:6px 18px; border-radius:8px; font-size:13px; font-weight:900; text-transform:uppercase; margin-bottom:14px;">
                            ⚠ Кастомні заміни (клієнт зі стандартного меню)
                        </div>
                        <div style="display:flex; flex-direction:column; gap:16px;">
                            @foreach($customClientTables as $table)
                                <div style="border:2px solid #ea580c; border-radius:10px; overflow:hidden; background:white;">
                                    <div style="background:#ea580c; color:white; padding:8px 16px; display:flex; align-items:center; gap:12px;">
                                        <span style="font-weight:900; font-size:15px;">{{ $table['client_label'] }}</span>
                                        <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:4px; font-size:12px;">{{ $table['project'] }}</span>
                                        <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:4px; font-size:12px; font-weight:700;">{{ $table['calories'] }} ккал</span>
                                    </div>
                                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:0;">
                                        @foreach($table['meals'] as $meal)
                                            @php
                                                $mealLower = mb_strtolower(trim($meal['meal']));
                                                $mealColor = '#94a3b8';
                                                if (str_contains($mealLower, 'сніданок'))      $mealColor = '#14b8a6';
                                                elseif (str_contains($mealLower, 'перекус 1')) $mealColor = '#84cc16';
                                                elseif (str_contains($mealLower, 'обід'))      $mealColor = '#fb923c';
                                                elseif (str_contains($mealLower, 'перекус 2')) $mealColor = '#f472b6';
                                                elseif (str_contains($mealLower, 'вечеря'))    $mealColor = '#38bdf8';
                                            @endphp
                                            <div style="border:1px solid #e5e7eb;">
                                                <div style="background:{{ $mealColor }}; color:white; padding:5px 10px; font-weight:900; font-size:11px; text-transform:uppercase;">
                                                    {{ $meal['meal'] }}
                                                </div>
                                                <div style="background:#fef3c7; padding:5px 10px; border-bottom:1px solid #fde68a;">
                                                    <div style="font-weight:900; font-size:13px; color:#78350f;">{{ $meal['dish_name'] }}</div>
                                                </div>
                                                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                    <tbody>
                                                        @foreach($meal['rows'] as $row)
                                                            <tr>
                                                                <td style="padding:4px 10px; border-bottom:1px solid #f3f4f6; color:#111827;">{{ $row['name'] }}</td>
                                                                <td style="padding:4px 8px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; color:#111827; width:60px;">{{ $row['weight'] }} г</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                                @if(!empty($meal['notes']))
                                                    <div style="padding:6px 10px; background:#fff7ed; font-size:11px; color:#7c2d12;">
                                                        @foreach($meal['notes'] as $note)
                                                            <div>• {{ $note['text'] }}</div>
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- ІНДИВІДУАЛЬНІ КЛІЄНТИ — одна картка на клієнта з усіма раціонами --}}
                @if(!empty($individualTables))
                    <div style="margin-top:24px; border-top:3px solid #7c3aed; padding-top:16px;">
                        <div style="background:#7c3aed; color:white; display:inline-block; padding:6px 18px; border-radius:8px; font-size:13px; font-weight:900; text-transform:uppercase; margin-bottom:14px;">
                            ★ Індивідуальні клієнти
                        </div>
                        <div style="display:flex; flex-direction:column; gap:16px;">
                            @foreach($individualTables as $table)
                                <div style="border:2px solid #7c3aed; border-radius:10px; overflow:hidden; background:white;">
                                    {{-- Заголовок клієнта --}}
                                    <div style="background:#7c3aed; color:white; padding:8px 16px; display:flex; align-items:center; gap:12px;">
                                        <span style="font-weight:900; font-size:15px;">{{ $table['client_label'] }}</span>
                                        <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:4px; font-size:12px;">{{ $table['project'] }}</span>
                                        <span style="background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:4px; font-size:12px; font-weight:700;">{{ $table['calories'] }} ккал</span>
                                    </div>
                                    {{-- Прийоми їжі --}}
                                    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:0;">
                                        @foreach($table['meals'] as $meal)
                                            @php
                                                $mealLower = mb_strtolower(trim($meal['meal']));
                                                $mealColor = '#94a3b8';
                                                if (str_contains($mealLower, 'сніданок'))      $mealColor = '#14b8a6';
                                                elseif (str_contains($mealLower, 'перекус 1')) $mealColor = '#84cc16';
                                                elseif (str_contains($mealLower, 'обід'))      $mealColor = '#fb923c';
                                                elseif (str_contains($mealLower, 'перекус 2')) $mealColor = '#f472b6';
                                                elseif (str_contains($mealLower, 'вечеря'))    $mealColor = '#38bdf8';
                                            @endphp
                                            <div style="border:1px solid #e5e7eb;">
                                                <div style="background:{{ $mealColor }}; color:white; padding:5px 10px; font-weight:900; font-size:11px; text-transform:uppercase;">
                                                    {{ $meal['meal'] }}
                                                </div>
                                                <div style="background:#dcfce7; padding:5px 10px; border-bottom:1px solid #bbf7d0;">
                                                    <div style="font-weight:900; font-size:13px; color:#052e16;">{{ $meal['dish_name'] }}</div>
                                                </div>
                                                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                                                    <tbody>
                                                        @foreach($meal['rows'] as $row)
                                                            <tr>
                                                                <td style="padding:4px 10px; border-bottom:1px solid #f3f4f6; color:#111827;">{{ $row['name'] }}</td>
                                                                <td style="padding:4px 8px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; color:#111827; width:60px;">{{ $row['weight'] }} г</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @endforeach
                {{-- кінець @foreach($report as $planId => $planBlock) --}}
            </div>
        @else
            <div style="text-align: center; padding: 50px; color: white; font-size: 18px; font-weight: 500;">
                Замовлень немає або меню на цей день не заповнено
            </div>
        @endif

        @endif {{-- кінець перемикача версій --}}
    </div>
</x-filament-panels::page>
