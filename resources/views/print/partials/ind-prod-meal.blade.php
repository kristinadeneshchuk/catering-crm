{{-- Готувальний: індивідуальні страви прийому, яких немає в циклічному. Картка на клієнта: № і доставка як у фасувальному. --}}
@if(!empty($cards))
<div style="margin:6px 0 18px; border:2px solid #7c3aed; border-radius:8px; overflow:hidden; background:#fff; color:#111827; -webkit-print-color-adjust:exact; print-color-adjust:exact; page-break-inside:auto;">
    <div style="background:#7c3aed; color:#fff; padding:5px 12px; font-weight:900; font-size:13px; text-transform:uppercase;">{{ $meal }} · індивідуальні ({{ count($cards) }})</div>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:0;">
        @foreach($cards as $c)
            <div style="border:1px solid #e5e7eb; break-inside:avoid; page-break-inside:avoid;">
                <div style="background:{{ $c['eve'] ? '#1e3a8a' : '#fde68a' }}; color:{{ $c['eve'] ? '#fff' : '#78350f' }}; padding:4px 8px; font-size:11px; font-weight:800;">
                    <span style="display:inline-block; background:#111; color:#fff; border-radius:50%; min-width:17px; height:17px; line-height:17px; font-size:10px; text-align:center;">{{ $c['n'] }}</span>
                    {{ $c['name'] }} · {{ $c['kcal'] }}
                    <span style="font-weight:500;">{{ $c['eve'] ? '🌙' : '☀' }} {{ $c['time'] }} · <b>{{ $c['weight'] }} г</b></span>
                    @if(!empty($c['diet_label']))<span style="background:#dc2626; color:#fff; padding:0 6px; border-radius:3px; margin-left:4px;">⚕ {{ $c['diet_label'] }}</span>@endif
                </div>
                <div style="background:#dcfce7; padding:4px 8px; border-bottom:1px solid #bbf7d0;">
                    <div style="font-weight:900; font-size:12px; color:#052e16;">{{ $c['dish_name'] }}@if(!$c['is_own']) <span style="font-size:9px; font-weight:600;">цикл</span>@endif</div>
                    <div style="font-size:9.5px; color:#065f46;">Нетто: {{ $c['total_netto'] }}г / Брутто: {{ $c['total_brutto'] }}г</div>
                </div>
                <table style="width:100%; border-collapse:collapse; font-size:11px;">
                    @foreach($c['components'] as $comp)
                        @if(($comp['type'] ?? '') === 'pf')
                            <tr style="background:#f3f4f6;"><td colspan="2" style="padding:2px 8px; color:#6b7280; font-style:italic; font-size:10px; border-bottom:1px solid #e5e7eb;">НФ: {{ $comp['name'] }} ({{ round($comp['weight_output'] ?? 0) }}г)</td></tr>
                            @foreach($comp['sub_ingredients'] ?? [] as $sub)
                                @php($sc = $sub['conflict'] ?? null)
                                <tr @if($sc && empty($sc['is_force_approved'])) style="background:#fef2f2;" @endif>
                                    <td style="padding:2px 8px 2px 16px; border-bottom:1px solid #f3f4f6; font-size:10.5px;">@include('filament.pages.partials.individual-conflict-name', ['name' => $sub['name'], 'conflict' => $sc])</td>
                                    <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; width:52px;">{{ round($sub['weight_brutto'] ?? 0) }} г</td>
                                </tr>
                            @endforeach
                        @else
                            @php($cc = $comp['conflict'] ?? null)
                            <tr @if($cc && empty($cc['is_force_approved'])) style="background:#fef2f2;" @endif>
                                <td style="padding:2px 8px; border-bottom:1px solid #f3f4f6;">@include('filament.pages.partials.individual-conflict-name', ['name' => $comp['name'], 'conflict' => $cc])</td>
                                <td style="padding:2px 6px; border-bottom:1px solid #f3f4f6; background:#e5e7eb; font-weight:800; text-align:center; width:52px;">{{ round($comp['weight_brutto'] ?? 0) }} г</td>
                            </tr>
                        @endif
                    @endforeach
                </table>
                @if(!empty($c['cooking_note']))
                    <div style="font-size:10px; color:#9b1c1c; font-weight:700; padding:3px 8px; background:#fef2f2;">📝 {{ $c['cooking_note'] }}</div>
                @endif
                @if(!empty($showRecipe) && !empty(trim(strip_tags($c['recipe'] ?? ''))))
                    <div style="border-top:1px dashed #fde68a; background:#fffbeb; padding:4px 8px; font-size:9.5px; line-height:1.45;">
                        <div style="font-weight:800; color:#92400e; font-size:8px; text-transform:uppercase;">Рецепт</div>
                        <div class="recipe-content">{!! \Illuminate\Support\Str::sanitizeHtml($c['recipe'] ?? '') !!}</div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
@endif
