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
        </div>

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

                @foreach($report as $table)
                    <div class="meal-section">
                        <div class="meal-badge">
                            {{ $table['meal'] }}: {{ $table['dish_name'] }}
                        </div>

                        <table class="matrix-table">
                            <thead>
                                <tr class="header-top">
                                    <th rowspan="2" class="row-label">{{ $table['dish_name'] }}</th>
                                    <th colspan="{{ count($table['columns']) }}">Програма (ккал / Клієнт)</th>
                                    <th rowspan="2" style="font-size:13px; font-weight:900; width:80px; vertical-align:middle;">ЗАГАЛОМ</th>
                                </tr>
                                <tr class="header-kcal">
                                    @foreach($table['columns'] as $label => $info)
                                        <th style="font-size:15px; font-weight:900;">
                                            {{ $label }}
                                            <span style="font-size:11px; font-weight:500; opacity:.8;">
                                                ({{ $info['count'] }})
                                            </span>
                                        </th>
                                    @endforeach
                                </tr>
                                <tr class="row-count">
                                    <td class="row-label">КІЛЬКІСТЬ ПОРЦІЙ</td>
                                    @foreach($table['columns'] as $info)
                                        <td>
                                            {{ $info['count'] }}
                                            @if(!empty($info['projects']))
                                                <div style="font-size:10px; font-weight:700; color:#111827; margin-top:2px;">
                                                    @foreach($info['projects'] as $p)
                                                        {{ $p['name'] }}: {{ $p['count'] }}<br>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </td>
                                    @endforeach
                                    @php $totalPortions = array_sum(array_column($table['columns'], 'count')); @endphp
                                    <td style="font-size:16px; font-weight:900;">
                                        {{ $totalPortions }}
                                    </td>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($table['rows'] as $row)
                                    <tr>
                                        <td class="row-label">{{ $row['original_name'] }}</td>
                                        @php $rowTotal = 0; @endphp
                                        @foreach($table['columns'] as $colKey => $info)
                                            @php
                                                $cell = $row['cells'][$colKey] ?? 0;
                                                $val  = is_array($cell) ? ($cell['val'] ?? 0) : $cell;
                                                $rowTotal += $val * ($info['count'] ?? 1);
                                            @endphp
                                            <td>
                                                <span style="font-weight: 800;">{{ $val }} г</span>
                                            </td>
                                        @endforeach
                                        <td style="font-size:14px; font-weight:900;">
                                            {{ round($rowTotal) }} г
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if(!empty($table['individual_notes']))
                            <div class="replacements-container">
                                <div style="font-weight: 900; text-transform: uppercase; margin-bottom: 5px; font-size: 11px;">
                                    Індивідуальні заміни:
                                </div>
                                @foreach($table['individual_notes'] as $note)
                                    <div style="margin-bottom: 2px; font-weight: 700;">
                                        • #{{ $note['id'] }} {{ $note['name'] }} ({{ $note['project'] }}, {{ $note['calories'] }} ккал): {{ $note['text'] }}
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div style="text-align: center; padding: 50px; color: white; font-size: 18px; font-weight: 500;">
                Замовлень немає або меню на цей день не заповнено
            </div>
        @endif
    </div>
</x-filament-panels::page>
