<x-filament-widgets::widget>
    @php
        $cards = [
            [
                'label'  => 'Нових сьогодні',
                'value'  => $countToday,
                'desc'   => 'На ' . $todayFmt,
                'icon'   => 'heroicon-o-sparkles',
                'accent' => '#ec4899',
                'bg'     => 'rgba(236,72,153,0.07)',
                'border' => 'rgba(236,72,153,0.25)',
            ],
            [
                'label'  => 'За 7 днів',
                'value'  => $countWeek,
                'desc'   => 'З ' . $weekStartFmt,
                'icon'   => 'heroicon-o-calendar-days',
                'accent' => '#a855f7',
                'bg'     => 'rgba(168,85,247,0.07)',
                'border' => 'rgba(168,85,247,0.25)',
            ],
            [
                'label'  => 'За 30 днів',
                'value'  => $countMonth,
                'desc'   => 'З ' . $monthStartFmt,
                'icon'   => 'heroicon-o-chart-bar',
                'accent' => '#6366f1',
                'bg'     => 'rgba(99,102,241,0.07)',
                'border' => 'rgba(99,102,241,0.25)',
            ],
            [
                'label'  => 'За свій період',
                'value'  => $hasCustom ? $countCustom : '—',
                'desc'   => $hasCustom ? ($customFromFmt . ' – ' . $customToFmt) : 'Оберіть діапазон',
                'icon'   => 'heroicon-o-adjustments-horizontal',
                'accent' => '#22c55e',
                'bg'     => 'rgba(34,197,94,0.07)',
                'border' => 'rgba(34,197,94,0.25)',
            ],
        ];
    @endphp

    <style>
        /* Flex-column з definite height (Filament тягне віджети рядка на одну
           висоту) роздував сітку карток на ~270px порожнечі. Блокова розкладка
           з відступами дає ту саму картинку, але висота = вміст. */
        .ncs-root > * + * { margin-top: 1rem; }
    </style>
    <div class="ncs-root">

        {{-- поля вибору свого періоду --}}
        <div>
            {{ $this->form }}
        </div>

        {{-- 4 плитки з підсумками --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:1rem; align-content:start; align-items:start;">
            @foreach($cards as $card)
            <div style="background:{{ $card['bg'] }}; border:1px solid {{ $card['border'] }}; border-radius:0.875rem; padding:1.25rem 1.375rem; display:flex; flex-direction:column; gap:0.75rem; position:relative; overflow:hidden;">

                <div style="position:absolute; top:0; left:0; right:0; height:2px; background:{{ $card['accent'] }}; border-radius:0.875rem 0.875rem 0 0;"></div>

                <div style="display:flex; align-items:center; justify-content:space-between;">
                    <span style="font-size:0.75rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.06em;">
                        {{ $card['label'] }}
                    </span>
                    <div style="width:2rem; height:2rem; border-radius:0.5rem; background:{{ $card['accent'] }}1a; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                        <x-dynamic-component :component="$card['icon']" style="width:1rem; height:1rem; color:{{ $card['accent'] }};" />
                    </div>
                </div>

                <div style="display:flex; align-items:baseline; gap:0.375rem;">
                    <span style="font-size:2rem; font-weight:800; color:#f1f5f9; line-height:1;">
                        {{ $card['value'] }}
                    </span>
                    @if($card['value'] !== '—')
                    <span style="font-size:0.8rem; color:#6b7280; font-weight:500;">осіб</span>
                    @endif
                </div>

                <span style="font-size:0.72rem; color:#6b7280;">
                    {{ $card['desc'] }}
                </span>
            </div>
            @endforeach
        </div>

        {{-- розподіл за джерелами --}}
        @if(!empty($bySource))
        <div style="background:rgba(148,163,184,0.05); border:1px solid rgba(148,163,184,0.15); border-radius:0.875rem; padding:1rem 1.25rem; display:flex; flex-direction:column; gap:0.625rem;">
            <span style="font-size:0.72rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.06em;">
                Джерела за {{ $sourceLabel }}
            </span>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem;">
                @foreach($bySource as $item)
                <div style="display:inline-flex; align-items:center; gap:0.5rem; background:rgba(99,102,241,0.08); border:1px solid rgba(99,102,241,0.2); border-radius:9999px; padding:0.3rem 0.75rem;">
                    <span style="font-size:0.78rem; color:#cbd5e1;">{{ $item['source'] }}</span>
                    <span style="font-size:0.78rem; font-weight:700; color:#a5b4fc;">{{ $item['count'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>
</x-filament-widgets::widget>
