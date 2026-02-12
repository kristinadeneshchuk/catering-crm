<x-filament-panels::page>
    <div id="packaging-list-master-container">
        <style>
           /* Стилі для відображення в адмінці (без змін, щоб було красиво) */
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
            
            <div style="margin-top: 20px; display: flex; justify-content: flex-end;">
                {{-- 
                    ГОЛОВНА ЗМІНА: 
                    Кнопка тепер веде на маршрут 'print.packaging-list' у новій вкладці 
                --}}
            <x-filament::button 
                tag="a"
                {{-- 🔥 Додаємо +1 день до дати в посиланні --}}
                :href="route('print.packaging-list', ['date' => \Carbon\Carbon::parse($data['date'])->addDay()->format('Y-m-d')])"
                target="_blank"
                icon="heroicon-m-printer"
                color="warning"
                size="lg"
            >
                Відкрити версію для друку
            </x-filament::button>

            @if($this->debugMessage)
                <div style="color: #fbbf24; margin-top: 10px; font-weight: bold; font-size: 12px;">{{ $this->debugMessage }}</div>
            @endif
        </div>

{{-- ПОПЕРЕДНІЙ ПЕРЕГЛЯД (Тільки на екрані) --}}
        
        @if(count($report) > 0)
            {{-- Показуємо білий "папір" тільки якщо є дані --}}
            <div class="bg-white p-6 rounded-xl border text-black">
                <h3 class="font-bold text-lg mb-6">Попередній перегляд на екрані:</h3>
                
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
                                </tr>
                                <tr class="header-kcal">
                                    @foreach($table['columns'] as $label => $info)
                                        <th>{{ $label }}</th>
                                    @endforeach
                                </tr>
                                <tr class="row-count">
                                    <td class="row-label">КІЛЬКІСТЬ ПОРЦІЙ</td>
                                    @foreach($table['columns'] as $info)
                                        <td>{{ $info['count'] }}</td>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($table['rows'] as $row)
                                    <tr>
                                        <td class="row-label">{{ $row['original_name'] }}</td>
                                        @foreach($table['columns'] as $key => $info)
                                            <td>
                                                <span style="font-weight: 800;">{{ $row['cells'][$key]['val'] }} г</span>
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if(!empty($table['individual_notes']))
                            <div class="replacements-container">
                                <div style="font-weight: 900; text-transform: uppercase; margin-bottom: 5px; font-size: 11px;">
                                    ⚠️ Індивідуальні заміни:
                                </div>
                                @foreach(array_unique($table['individual_notes']) as $note)
                                    <div style="margin-bottom: 2px; font-weight: 700;">{{ $note }}</div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            {{-- Якщо даних немає - показуємо просто текст на темному фоні --}}
            <div style="text-align: center; padding: 50px; color: white; font-size: 18px; font-weight: 500;">
                Замовлень немає або меню на цей день не заповнено
            </div>
        @endif
    </div>
</x-filament-panels::page>