<x-filament-widgets::widget>
    <x-filament::section heading="Енергія по місяцях">
        @php
            $n = fn ($v) => number_format((float) $v, 0, '.', ' ');
            $names = [1 => 'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень', 'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень'];
        @endphp
        <div style="overflow-x:auto">
            <table style="width:100%;font-size:13px;border-collapse:collapse">
                <thead>
                    <tr style="text-align:right;opacity:.65">
                        <th style="text-align:left;padding:4px 8px">Місяць</th>
                        <th style="padding:4px 8px">Мережа, кВт</th>
                        <th style="padding:4px 8px">Мережа, ₴</th>
                        <th style="padding:4px 8px">Генератор, л</th>
                        <th style="padding:4px 8px">Генератор, ₴</th>
                        <th style="padding:4px 8px">Разом, ₴</th>
                        <th style="padding:4px 8px">Раціонів</th>
                        <th style="padding:4px 8px">На раціон, ₴</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($months as $m)
                        <tr style="text-align:right;border-top:1px solid rgba(128,128,128,.2)">
                            <td style="text-align:left;padding:5px 8px;font-weight:600">
                                {{ $names[(int) substr($m['month'], 5, 2)] }} {{ substr($m['month'], 0, 4) }}
                                @unless ($m['complete'])
                                    <span style="font-weight:400;opacity:.6" title="Показники покривають не весь місяць">· {{ $m['days'] }} дн. з показниками</span>
                                @endunless
                            </td>
                            <td style="padding:5px 8px">{{ $n($m['grid_kwh']) }}</td>
                            <td style="padding:5px 8px">{{ $n($m['grid_cost']) }}</td>
                            <td style="padding:5px 8px">{{ $m['fuel_l'] ? $m['fuel_l'] : '—' }}</td>
                            <td style="padding:5px 8px">{{ $m['fuel_cost'] ? $n($m['fuel_cost']) : '—' }}</td>
                            <td style="padding:5px 8px;font-weight:700">{{ $n($m['total']) }}</td>
                            <td style="padding:5px 8px">{{ $n($m['rations']) }}</td>
                            <td style="padding:5px 8px">{{ $m['per_ration'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="font-size:12px;opacity:.6;margin-top:8px">
            Спожите між двома показниками розкладено по днях рівномірно. Якщо показники покривають місяць не повністю, цифри мережі за нього неповні.
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
