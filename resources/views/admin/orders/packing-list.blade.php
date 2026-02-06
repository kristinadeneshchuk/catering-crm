<x-filament-panels::page>
    <style>
        @media print { .no-print { display: none !important; } .page-break { page-break-after: always; } }
        .facovka-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; background: white; color: black; font-size: 13px; table-layout: fixed; }
        .facovka-table th, .facovka-table td { border: 1px solid #000; padding: 4px 2px; text-align: center; overflow: hidden; }
        .header-main { background: #4ade80; font-weight: bold; }
        .header-calories { background: #f3f4f6; font-weight: bold; font-size: 11px; }
        .ingredient-name { text-align: left; background: #f9fafb; font-weight: bold; padding-left: 5px; width: 180px; }
        .count-row { background: #dcfce7; font-weight: 900; font-size: 14px; }
        .cell-repl { background: #fef9c3; color: #92400e; font-weight: bold; } /* Підсвітка заміни */
        .repl-name { font-size: 9px; display: block; color: #b91c1c; text-transform: uppercase; }
    </style>

    <div class="no-print">
        <form wire:submit.prevent="calculate">
            {{ $this->form }}
        </form>
    </div>

    @foreach($report as $table)
        <div class="page-break">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                <h2 style="font-size: 16px; font-weight: 900; text-transform: uppercase; color: #1e40af;">
                    {{ $table['meal'] }}: {{ $table['dish_name'] }}
                </h2>
                <span style="font-size: 12px; color: #6b7280;">Дата: {{ $data['date'] }}</span>
            </div>

            <table class="facovka-table">
                <thead>
                    <tr class="header-main">
                        <th style="width: 180px;">Інгредієнт</th>
                        @foreach($table['columns'] as $kcal => $info)
                            <th>{{ $kcal }}</th>
                        @endforeach
                    </tr>
                    <tr class="count-row">
                        <td class="ingredient-name">КІЛЬКІСТЬ ПОРЦІЙ</td>
                        @foreach($table['columns'] as $info)
                            <td>{{ $info['count'] }}</td>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($table['rows'] as $row)
                        <tr>
                            <td class="ingredient-name">{{ $row['original_name'] }}</td>
                            @foreach($table['columns'] as $kcal => $info)
                                @php $cell = $row['cells'][$kcal]; @endphp
                                <td class="{{ $cell['is_repl'] ? 'cell-repl' : '' }}">
                                    @if($cell['is_repl'])
                                        <span class="repl-name">{{ $cell['name'] }}</span>
                                    @endif
                                    <span style="font-size: 14px;">{{ $cell['val'] }} г</span>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endforeach
</x-filament-panels::page>