<x-filament-widgets::widget>
    @if (count($rows))
        <x-filament::section heading="Розрахунки з компаніями">
            @php $n = fn ($v) => number_format((float) $v, 0, '.', ' '); @endphp
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13px;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:right;opacity:.65">
                            <th style="text-align:left;padding:4px 8px">Компанія</th>
                            <th style="text-align:left;padding:4px 8px">Клієнт CRM</th>
                            <th style="padding:4px 8px">Нараховано, ₴</th>
                            <th style="padding:4px 8px">Оплачено, ₴</th>
                            <th style="padding:4px 8px">До сплати, ₴</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $r)
                            <tr style="text-align:right;border-top:1px solid rgba(128,128,128,.2)">
                                <td style="text-align:left;padding:5px 8px;font-weight:600">{{ $r['company'] ?: '—' }}</td>
                                <td style="text-align:left;padding:5px 8px"><a href="{{ $r['url'] }}" style="text-decoration:underline">{{ $r['client'] }}</a></td>
                                <td style="padding:5px 8px">{{ $n($r['charged']) }}</td>
                                <td style="padding:5px 8px">{{ $n($r['paid']) }}</td>
                                <td style="padding:5px 8px;font-weight:700;{{ $r['debt'] > 0.5 ? 'color:#dc2626' : ($r['debt'] < -0.5 ? 'color:#16a34a' : '') }}">
                                    {{ $r['debt'] < -0.5 ? 'переплата '.$n(-$r['debt']) : $n($r['debt']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div style="font-size:12px;opacity:.6;margin-top:8px">
                Нараховано — частина компанії за всі дні, що надійшли з Lunch Hub (те, що співробітники платять самі, сюди не входить). Оплату вносить кнопка «Оплата обідів» у рядку компанії.
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
