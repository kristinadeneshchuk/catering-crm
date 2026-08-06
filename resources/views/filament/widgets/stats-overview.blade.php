<x-filament-widgets::widget>
    @php
        $cards = [
            [
                'label'   => 'Порцій сьогодні',
                'value'   => $todayCount,
                'unit'    => 'шт',
                'desc'    => 'Активні замовлення на ' . now()->format('d.m'),
                'icon'    => 'heroicon-o-fire',
                'accent'  => '#22c55e',
                'bg'      => 'rgba(34,197,94,0.07)',
                'border'  => 'rgba(34,197,94,0.25)',
            ],
            [
                'label'   => 'Порцій завтра',
                'value'   => $tomorrowCount,
                'unit'    => 'шт',
                'desc'    => 'Планування на ' . now()->addDay()->format('d.m'),
                'icon'    => 'heroicon-o-arrow-right-circle',
                'accent'  => '#38bdf8',
                'bg'      => 'rgba(56,189,248,0.07)',
                'border'  => 'rgba(56,189,248,0.25)',
            ],
            [
                'label'   => 'Активних клієнтів',
                'value'   => $totalActive,
                'unit'    => 'осіб',
                'desc'    => 'Діючі пакети',
                'icon'    => 'heroicon-o-user-group',
                'accent'  => '#818cf8',
                'bg'      => 'rgba(129,140,248,0.07)',
                'border'  => 'rgba(129,140,248,0.25)',
            ],
            [
                'label'   => 'Закінчуються скоро',
                'value'   => $expiringSoon,
                'unit'    => 'замовл.',
                'desc'    => 'Потребують продовження',
                'icon'    => 'heroicon-o-bell-alert',
                'accent'  => $expiringSoon > 0 ? '#fb923c' : '#6b7280',
                'bg'      => $expiringSoon > 0 ? 'rgba(251,146,60,0.07)' : 'rgba(55,65,81,0.2)',
                'border'  => $expiringSoon > 0 ? 'rgba(251,146,60,0.3)' : 'rgba(55,65,81,0.4)',
            ],
            [
                'label'   => 'Борги клієнтів',
                'value'   => $unpaidCount,
                'unit'    => 'клієнт.',
                'desc'    => number_format($unpaidSum, 0, '.', ' ') . ' ₴ до отримання',
                'icon'    => 'heroicon-o-credit-card',
                'accent'  => $unpaidCount > 0 ? '#f87171' : '#22c55e',
                'bg'      => $unpaidCount > 0 ? 'rgba(248,113,113,0.07)' : 'rgba(34,197,94,0.07)',
                'border'  => $unpaidCount > 0 ? 'rgba(248,113,113,0.25)' : 'rgba(34,197,94,0.25)',
            ],
            [
                'label'   => 'День меню',
                'value'   => '№ ' . $menuDay,
                'unit'    => '',
                'desc'    => 'З циклу ' . $cycleDays . ' днів',
                'icon'    => 'heroicon-o-calendar-days',
                'accent'  => '#a78bfa',
                'bg'      => 'rgba(167,139,250,0.07)',
                'border'  => 'rgba(167,139,250,0.25)',
            ],
        ];
    @endphp

    <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:1rem;">
        @foreach($cards as $card)
        <div style="background:{{ $card['bg'] }}; border:1px solid {{ $card['border'] }}; border-radius:0.875rem; padding:1.25rem 1.375rem; display:flex; flex-direction:column; gap:0.75rem; position:relative; overflow:hidden;">

            {{-- акцентна смужка зверху --}}
            <div style="position:absolute; top:0; left:0; right:0; height:2px; background:{{ $card['accent'] }}; border-radius:0.875rem 0.875rem 0 0;"></div>

            {{-- заголовок + іконка --}}
            <div style="display:flex; align-items:center; justify-content:space-between;">
                <span style="font-size:0.75rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.06em;">
                    {{ $card['label'] }}
                </span>
                <div style="width:2rem; height:2rem; border-radius:0.5rem; background:{{ $card['accent'] }}1a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                    <x-dynamic-component :component="$card['icon']" style="width:1rem; height:1rem; color:{{ $card['accent'] }};" />
                </div>
            </div>

            {{-- число --}}
            <div style="display:flex; align-items:baseline; gap:0.375rem;">
                <span style="font-size:2rem; font-weight:800; color:#f1f5f9; line-height:1;">
                    {{ $card['value'] }}
                </span>
                @if($card['unit'])
                <span style="font-size:0.8rem; color:#6b7280; font-weight:500;">{{ $card['unit'] }}</span>
                @endif
            </div>

            {{-- опис --}}
            <span style="font-size:0.72rem; color:#6b7280; margin-top:auto;">
                {{ $card['desc'] }}
            </span>
        </div>
        @endforeach
    </div>
</x-filament-widgets::widget>
