<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Навантаження на кухню</x-slot>
        <x-slot name="headerEnd">
            <span style="font-size:0.75rem; color:#6b7280;">найближчі 20 днів</span>
        </x-slot>

        @php
            $maxTotal = max(collect($loadList)->pluck('total')->max(), 1);

            $dayAbbr = [
                'Неділя'    => 'Нд',
                'Понеділок' => 'Пн',
                'Вівторок'  => 'Вт',
                'Середа'    => 'Ср',
                'Четвер'    => 'Чт',
                'П\'ятниця' => 'Пт',
                'Субота'    => 'Сб',
            ];
        @endphp

        <div style="display:flex; align-items:center; gap:0.5rem;">

            {{-- стрілка ліворуч --}}
            <button onclick="document.getElementById('load-scroll').scrollBy({left:-300,behavior:'smooth'})"
                    style="flex-shrink:0; background:#1f2937; border:1px solid #374151; border-radius:50%; width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#9ca3af; font-size:1.2rem; line-height:1; transition:background 0.15s;"
                    onmouseover="this.style.background='#374151'; this.style.color='#fff'"
                    onmouseout="this.style.background='#1f2937'; this.style.color='#9ca3af'">‹</button>

            {{-- скрол-контейнер --}}
            <div id="load-scroll" style="overflow-x:auto; flex:1; padding:0.25rem 0 0.75rem; scrollbar-width:thin; scrollbar-color:#4b5563 transparent;">
                <div style="display:flex; gap:0.5rem;">
                    @foreach($loadList as $row)
                    @php
                        $isToday    = $loop->first;
                        $isTomorrow = $loop->iteration === 2;
                        $total      = $row['total'];
                        $pct        = round(($total / $maxTotal) * 100);
                        $abbr       = $dayAbbr[$row['day_name']] ?? mb_substr($row['day_name'], 0, 2);

                        if ($isToday) {
                            $borderColor = '#3b82f6'; $bgColor = 'rgba(59,130,246,0.12)';
                            $countColor = '#60a5fa'; $badgeBg = '#2563eb'; $badgeText = 'Сьогодні';
                        } elseif ($isTomorrow) {
                            $borderColor = '#8b5cf6'; $bgColor = 'rgba(139,92,246,0.1)';
                            $countColor = '#a78bfa'; $badgeBg = '#7c3aed'; $badgeText = 'Завтра';
                        } elseif ($total >= 60) {
                            $borderColor = '#dc2626'; $bgColor = 'rgba(220,38,38,0.08)';
                            $countColor = '#f87171'; $badgeBg = null; $badgeText = null;
                        } elseif ($total >= 35) {
                            $borderColor = '#d97706'; $bgColor = 'rgba(217,119,6,0.08)';
                            $countColor = '#fbbf24'; $badgeBg = null; $badgeText = null;
                        } else {
                            $borderColor = '#374151'; $bgColor = 'rgba(55,65,81,0.25)';
                            $countColor = '#9ca3af'; $badgeBg = null; $badgeText = null;
                        }
                    @endphp

                    <div style="width:90px; flex-shrink:0; background:{{ $bgColor }}; border:1px solid {{ $borderColor }}; border-radius:0.625rem; padding:0.875rem 0.5rem 0.625rem; text-align:center; position:relative; cursor:default; transition:transform 0.15s, box-shadow 0.15s;"
                         onmouseover="this.style.transform='translateY(-3px)'; this.style.boxShadow='0 4px 12px rgba(0,0,0,0.3)'"
                         onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none'">

                        @if($badgeText)
                        <div style="position:absolute; top:-1px; left:50%; transform:translateX(-50%); background:{{ $badgeBg }}; color:white; font-size:0.5rem; font-weight:700; padding:1px 6px; border-radius:0 0 4px 4px; text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap;">
                            {{ $badgeText }}
                        </div>
                        @endif

                        <p style="font-size:0.8rem; font-weight:700; color:{{ $borderColor }}; margin:{{ $badgeText ? '0.75rem' : '0' }} 0 0.1rem; text-transform:uppercase; letter-spacing:0.05em;">
                            {{ $abbr }}
                        </p>
                        <p style="font-size:0.65rem; color:#6b7280; margin:0 0 0.625rem; font-family:monospace;">
                            {{ substr($row['date'], 0, 5) }}
                        </p>

                        <p style="font-size:1.75rem; font-weight:900; color:{{ $countColor }}; margin:0; line-height:1;">
                            {{ $total }}
                        </p>
                        <p style="font-size:0.55rem; color:#6b7280; margin:0.125rem 0 0.625rem; text-transform:uppercase; letter-spacing:0.05em;">шт</p>

                        <div style="height:3px; background:rgba(55,65,81,0.6); border-radius:9999px; overflow:hidden;">
                            <div style="height:100%; width:{{ $pct }}%; background:{{ $countColor }}; border-radius:9999px;"></div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- стрілка праворуч --}}
            <button onclick="document.getElementById('load-scroll').scrollBy({left:300,behavior:'smooth'})"
                    style="flex-shrink:0; background:#1f2937; border:1px solid #374151; border-radius:50%; width:2rem; height:2rem; display:flex; align-items:center; justify-content:center; cursor:pointer; color:#9ca3af; font-size:1.2rem; line-height:1; transition:background 0.15s;"
                    onmouseover="this.style.background='#374151'; this.style.color='#fff'"
                    onmouseout="this.style.background='#1f2937'; this.style.color='#9ca3af'">›</button>

        </div>
    </x-filament::section>
</x-filament-widgets::widget>
