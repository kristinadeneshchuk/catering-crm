@php
    /** @var array $summary */
    $fmt = fn ($v) => number_format((float) $v, 0, '.', ' ');
@endphp

@push('styles')
<style>
    .dcr-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif; }
    .dcr-wrap * { box-sizing: border-box; }
    .dcr-input {
        background: #27272a;
        border: 1.5px solid #3f3f46;
        border-radius: 10px;
        padding: 8px 14px;
        color: #f4f4f5;
        font-size: 13px;
        font-weight: 600;
        outline: none;
        cursor: pointer;
        color-scheme: dark;
    }
    .dcr-input:focus { border-color: #3b82f6; }
    .dcr-tile-label {
        color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;
    }
    .dcr-tile-value { font-size:22px;font-weight:900;line-height:1; }
    .dcr-tile-hint { color:#52525b;font-size:11px;margin-top:6px; }
</style>
@endpush

<div class="dcr-wrap" style="margin-bottom:18px;">

    {{-- Шапка з датою --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:18px;">🧾</span>
            <h2 style="font-size:16px;font-weight:800;color:#f4f4f5;margin:0;">Каса за день</h2>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
            <span style="color:#71717a;font-size:12px;">Дата:</span>
            <input type="date" wire:model.live="cashDate" class="dcr-input">
        </div>
    </div>

    {{-- Залишки на рахунках + прихід сьогодні по рахунках --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:14px;padding:14px 18px;margin-bottom:12px;">
        <div class="dcr-tile-label">Залишки на рахунках (зараз)</div>
        <div style="display:flex;flex-wrap:wrap;gap:20px 26px;font-size:13px;">
            @foreach ($summary['accounts']['rows'] as $acc)
                <div>
                    <span style="color:#71717a;">{{ $acc['name'] }}:</span>
                    <span style="font-weight:700;color:{{ $acc['balance'] < 0 ? '#f87171' : '#f4f4f5' }};">
                        {{ $fmt($acc['balance']) }} ₴
                    </span>
                </div>
            @endforeach
            <div style="border-left:1px solid #3f3f46;padding-left:20px;">
                <span style="color:#71717a;">Разом:</span>
                <span style="font-weight:900;color:#f4f4f5;">{{ $fmt($summary['accounts']['total']) }} ₴</span>
            </div>
        </div>

        @if (! empty($summary['incomeByAccount']))
            @php $totalInflow = array_sum(array_column($summary['incomeByAccount'], 'total')); @endphp
            <div style="border-top:1px solid #27272a;margin-top:12px;padding-top:12px;">
                <div class="dcr-tile-label" style="color:#34d399;">Прийшло сьогодні</div>
                <div style="display:flex;flex-wrap:wrap;gap:20px 26px;font-size:13px;">
                    @foreach ($summary['incomeByAccount'] as $row)
                        <div>
                            <span style="color:#71717a;">{{ $row['name'] }}:</span>
                            @if ($row['total'] > 0)
                                <span style="font-weight:700;color:#34d399;">+{{ $fmt($row['total']) }} ₴</span>
                                <span style="color:#52525b;font-size:11px;">({{ $row['count'] }})</span>
                            @else
                                <span style="font-weight:600;color:#52525b;">0 ₴</span>
                            @endif
                        </div>
                    @endforeach
                    <div style="border-left:1px solid #3f3f46;padding-left:20px;">
                        <span style="color:#71717a;">Разом:</span>
                        <span style="font-weight:900;color:{{ $totalInflow > 0 ? '#34d399' : '#71717a' }};">
                            {{ $totalInflow > 0 ? '+' : '' }}{{ $fmt($totalInflow) }} ₴
                        </span>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- 4 плитки руху дня --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3" style="margin-bottom:12px;">
        {{-- Прихід --}}
        <div style="background:#18181b;border:1px solid #065f46;border-radius:14px;padding:16px 18px;">
            <p class="dcr-tile-label">📥 Прихід дня</p>
            <p class="dcr-tile-value" style="color:#34d399;">+{{ $fmt($summary['income']['sum']) }} ₴</p>
            <p class="dcr-tile-hint">{{ $summary['income']['count'] }} оплат</p>
        </div>
        {{-- Витрати --}}
        <div style="background:#18181b;border:1px solid #7f1d1d;border-radius:14px;padding:16px 18px;">
            <p class="dcr-tile-label">📤 Витрати дня</p>
            <p class="dcr-tile-value" style="color:#f87171;">−{{ $fmt($summary['expenses']['sum']) }} ₴</p>
            <p class="dcr-tile-hint">{{ $summary['expenses']['count'] }} шт.</p>
        </div>
        {{-- Виплати ЗП --}}
        <div style="background:#18181b;border:1px solid #92400e;border-radius:14px;padding:16px 18px;">
            <p class="dcr-tile-label">💰 Виплати ЗП</p>
            <p class="dcr-tile-value" style="color:#fbbf24;">−{{ $fmt($summary['salaries']['sum']) }} ₴</p>
            <p class="dcr-tile-hint">{{ $summary['salaries']['count'] }} шт.</p>
        </div>
        {{-- Закупівлі --}}
        <div style="background:#18181b;border:1px solid #334155;border-radius:14px;padding:16px 18px;">
            <p class="dcr-tile-label">🏭 Закупівлі</p>
            <p class="dcr-tile-value" style="color:#94a3b8;">−{{ $fmt($summary['purchases']['sum']) }} ₴</p>
            <p class="dcr-tile-hint">{{ $summary['purchases']['count'] }} шт.</p>
        </div>
    </div>

    {{-- ФОП + Не оплатили --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div style="background:#18181b;border:1px solid #1e3a5f;border-radius:14px;padding:16px 18px;">
            <div style="display:flex;justify-content:space-between;align-items:center;">
                <p class="dcr-tile-label" style="margin-bottom:0;">📊 ФОП нараховано за день</p>
                <p class="dcr-tile-value" style="color:#60a5fa;">−{{ $fmt($summary['fop']['total']) }} ₴</p>
            </div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;font-size:12px;color:#a1a1aa;margin-top:8px;">
                <span>Кухня: <b style="color:#f4f4f5;">{{ $fmt($summary['fop']['kitchen']) }} ₴</b></span>
                <span>Курʼєри: <b style="color:#f4f4f5;">{{ $fmt($summary['fop']['couriers']) }} ₴</b></span>
                <span>Інше: <b style="color:#f4f4f5;">{{ $fmt($summary['fop']['other']) }} ₴</b></span>
            </div>
        </div>

        <div x-data="{ open: false }"
             style="background:#18181b;border:1px solid #7c2d12;border-radius:14px;padding:16px 18px;">
            <button type="button" @click="open = !open"
                    style="width:100%;display:flex;justify-content:space-between;align-items:center;background:none;border:0;cursor:pointer;padding:0;">
                <p class="dcr-tile-label" style="margin-bottom:0;">
                    ⚠️ Не оплатили сьогодні
                    @if ($summary['unpaid']['count'] > 0)
                        · <span style="color:#f4f4f5;">{{ $summary['unpaid']['count'] }}</span> клієнт(и)
                    @endif
                </p>
                <p class="dcr-tile-value" style="color:#fb923c;">
                    {{ $fmt($summary['unpaid']['sum']) }} ₴
                    @if ($summary['unpaid']['count'] > 0)
                        <span style="font-size:11px;color:#71717a;margin-left:4px;" x-text="open ? '▲' : '▼'"></span>
                    @endif
                </p>
            </button>

            @if ($summary['unpaid']['count'] > 0)
                <div x-show="open" x-transition style="margin-top:12px;max-height:220px;overflow:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                        <tbody>
                        @foreach ($summary['unpaid']['rows'] as $row)
                            <tr style="border-top:1px solid #27272a;">
                                <td style="padding:6px 0;">
                                    <a href="{{ \App\Filament\Resources\ClientResource::getUrl('edit', ['record' => $row['id']]) }}"
                                       style="color:#f4f4f5;text-decoration:none;font-weight:600;">
                                        {{ $row['name'] }}
                                    </a>
                                </td>
                                <td style="padding:6px 8px;color:#71717a;">{{ $row['phone'] }}</td>
                                <td style="padding:6px 0;text-align:right;color:#f87171;font-weight:700;">
                                    {{ $fmt($row['debt']) }} ₴
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
