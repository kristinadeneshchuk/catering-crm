<x-filament-panels::page>
@push('styles')
<style>
    .pr-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif; }
    .pr-wrap * { box-sizing: border-box; }
    .pr-input {
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
    .pr-input:focus { border-color: #3b82f6; }
    .fi-page-header { display: none !important; }
</style>
@endpush

@php
    $data = $this->getData();
    $rows = $data['rows'];
    $tot  = $data['totals'];
    $gtot = $data['group_totals'];
    $perPortion = $data['per_portion'];
    $groups = $data['groups'];
@endphp

<div class="pr-wrap">

    {{-- ШАПКА --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div>
            <h1 style="font-size:24px;font-weight:800;color:#f4f4f5;line-height:1.2;margin:0;">Зарплати</h1>
            <p style="font-size:13px;color:#71717a;margin-top:2px;">Зведення нарахувань за період. Деталі та виплата — у «Співробітники» (кнопка «Історія / Виплата»).</p>
        </div>

        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="color:#71717a;font-size:13px;">Період:</span>
            <input type="date" wire:model.live="startDate" class="pr-input">
            <span style="color:#52525b;">→</span>
            <input type="date" wire:model.live="endDate" class="pr-input">
        </div>
    </div>

    {{-- ФІЛЬТР ПО ГРУПАХ --}}
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px;">
        @foreach(['all' => 'Всі'] + $groups as $key => $label)
        <button wire:click="$set('groupFilter', '{{ $key }}')"
            style="padding:7px 16px;border-radius:20px;font-size:13px;font-weight:{{ $groupFilter === $key ? '600' : '500' }};
                   border:1.5px solid {{ $groupFilter === $key ? '#3b82f6' : '#3f3f46' }};
                   background:{{ $groupFilter === $key ? '#1e3a5f' : 'transparent' }};
                   color:{{ $groupFilter === $key ? '#60a5fa' : '#71717a' }};cursor:pointer;">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- КАРТКИ ПІДСУМКІВ --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3" style="margin-bottom:18px;">
        <div style="background:#18181b;border:1px solid #27272a;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Зарплата</p>
            <p style="color:#f4f4f5;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['salary'], 0, '.', ' ') }} ₴</p>
        </div>
        <div style="background:#18181b;border:1px solid #2e1065;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Бонус</p>
            <p style="color:#a78bfa;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['bonus'], 0, '.', ' ') }} ₴</p>
        </div>
        <div style="background:#18181b;border:1px solid #7f1d1d;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Штраф</p>
            <p style="color:#f87171;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['penalty'], 0, '.', ' ') }} ₴</p>
        </div>
        <div style="background:#18181b;border:1px solid #92400e;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Компенсація</p>
            <p style="color:#fbbf24;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['compensation'], 0, '.', ' ') }} ₴</p>
        </div>
        <div style="background:linear-gradient(135deg,#052e16,#0a1f14);border:1px solid #065f46;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Сума</p>
            <p style="color:#34d399;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['sum'], 0, '.', ' ') }} ₴</p>
        </div>
    </div>

    {{-- ТАБЛИЦЯ --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;overflow:hidden;margin-bottom:18px;">
        <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="background:#111113;border-bottom:1px solid #27272a;">
                    <th style="text-align:left;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Співробітник</th>
                    <th style="text-align:left;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Посада</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Зарплата</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Бонус</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Штраф</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Компенсація</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Сума</th>
                </tr>
            </thead>
            <tbody>
                @php $prevGroup = null; @endphp
                @foreach($rows as $row)
                    @if($prevGroup !== $row['group'])
                        <tr><td colspan="7" style="padding:14px 16px 8px;background:#0c0c0e;border-top:1px solid #27272a;">
                            <span style="color:#a1a1aa;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;">{{ $row['group_label'] }}</span>
                        </td></tr>
                        @php $prevGroup = $row['group']; @endphp
                    @endif
                    <tr style="border-bottom:1px solid #1f1f22;">

                        <td style="padding:11px 16px;">
                            <div style="color:#f4f4f5;font-weight:600;">{{ $row['name'] }}</div>
                            @if($row['balance'] != 0)
                                <div style="color:{{ $row['balance'] > 0 ? '#34d399' : '#f87171' }};font-size:11px;margin-top:2px;">
                                    Баланс: {{ number_format($row['balance'], 2, '.', ' ') }} ₴
                                </div>
                            @endif
                        </td>

                        <td style="padding:11px 16px;color:#a1a1aa;font-size:12px;">
                            {{ $row['position'] }}
                            @if($row['payment_type'] === 'per_month')
                                <span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#1e3a5f;color:#60a5fa;border-radius:4px;font-size:10px;font-weight:600;">оклад</span>
                            @endif
                        </td>

                        <td style="padding:11px 16px;text-align:right;color:#f4f4f5;font-weight:600;">
                            {{ $row['salary'] > 0 ? number_format($row['salary'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                        <td style="padding:11px 16px;text-align:right;color:{{ $row['bonus'] > 0 ? '#a78bfa' : '#3f3f46' }};font-weight:{{ $row['bonus'] > 0 ? '600' : '400' }};">
                            {{ $row['bonus'] > 0 ? '+' . number_format($row['bonus'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                        <td style="padding:11px 16px;text-align:right;color:{{ $row['penalty'] > 0 ? '#f87171' : '#3f3f46' }};font-weight:{{ $row['penalty'] > 0 ? '600' : '400' }};">
                            {{ $row['penalty'] > 0 ? '−' . number_format($row['penalty'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                        <td style="padding:11px 16px;text-align:right;color:{{ $row['compensation'] > 0 ? '#fbbf24' : '#3f3f46' }};font-weight:{{ $row['compensation'] > 0 ? '600' : '400' }};">
                            {{ $row['compensation'] > 0 ? '+' . number_format($row['compensation'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                        <td style="padding:11px 16px;text-align:right;color:#34d399;font-weight:800;font-size:14px;">
                            {{ number_format($row['sum'], 0, '.', ' ') }} ₴
                        </td>
                    </tr>
                @endforeach

                @if(empty($rows))
                <tr><td colspan="7" style="padding:40px 20px;text-align:center;color:#52525b;font-size:13px;">Немає даних за обраний період.</td></tr>
                @endif
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #3f3f46;background:linear-gradient(135deg,#111113,#18181b);">
                    <td colspan="2" style="padding:14px 16px;color:#a1a1aa;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Разом</td>
                    <td style="padding:14px 16px;text-align:right;color:#f4f4f5;font-weight:900;font-size:14px;">{{ number_format($tot['salary'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#a78bfa;font-weight:900;font-size:14px;">{{ number_format($tot['bonus'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#f87171;font-weight:900;font-size:14px;">{{ number_format($tot['penalty'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#fbbf24;font-weight:900;font-size:14px;">{{ number_format($tot['compensation'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#34d399;font-weight:900;font-size:18px;">{{ number_format($tot['sum'], 0, '.', ' ') }} ₴</td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>

    {{-- ГРН / ПОРЦІЯ ПО ГРУПАХ --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:12px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <tbody>
                @foreach(['kitchen','couriers','management','marketing','other'] as $g)
                    @if(isset($perPortion[$g]))
                        @php
                            $cpp = $perPortion[$g]['rate'];
                            $color = '#27272a';
                            if ($cpp > 0) {
                                if ($cpp >= 80 && $cpp <= 90)  $color = '#14b8a6';
                                elseif ($cpp < 120)            $color = '#22c55e';
                                else                           $color = '#ef4444';
                            }
                        @endphp
                        <tr style="border-bottom:1px solid #1f1f22;">
                            <td style="padding:10px 16px;color:#a1a1aa;font-size:12px;font-weight:600;">
                                ₴ / порція <span style="color:#3f3f46;font-weight:400;">({{ mb_strtolower($groups[$g] ?? $g) }})</span>
                            </td>
                            <td style="padding:10px 16px;text-align:right;color:#52525b;font-size:11px;">
                                {{ number_format($perPortion[$g]['fop'], 0, '.', ' ') }} ₴ / {{ $perPortion[$g]['portions'] }} порц
                            </td>
                            <td style="padding:10px 16px;text-align:right;color:{{ $color }};font-weight:700;font-size:14px;width:120px;">
                                @if($cpp > 0)
                                    {{ number_format($cpp, 2, '.', ' ') }} ₴
                                @else
                                    <span style="color:#27272a;">–</span>
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Легенда градації --}}
    <div style="margin-top:14px;padding:12px 18px;background:#18181b;border:1px solid #27272a;border-radius:12px;">
        <div style="color:#52525b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:8px;">
            Собівартість праці на 1 порцію — градація
        </div>
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#14b8a6;display:inline-block;"></span>
                <span style="color:#a1a1aa;font-size:12px;"><b style="color:#14b8a6;">80–90 ₴</b> — ідеально</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#22c55e;display:inline-block;"></span>
                <span style="color:#a1a1aa;font-size:12px;"><b style="color:#22c55e;">до 120 ₴</b> — норма</span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span style="width:10px;height:10px;border-radius:50%;background:#ef4444;display:inline-block;"></span>
                <span style="color:#a1a1aa;font-size:12px;"><b style="color:#ef4444;">120+ ₴</b> — критично</span>
            </div>
        </div>
        <p style="color:#52525b;font-size:11px;margin:8px 0 0;">
            Кухня рахується на порції наступного дня (готують сьогодні на завтра). Кур'єри — на порції того самого дня. Менеджмент / маркетинг — на всі порції періоду.
        </p>
    </div>


</div>
</x-filament-panels::page>
