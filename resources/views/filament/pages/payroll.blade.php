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
    <div class="grid grid-cols-2 md:grid-cols-6 gap-3" style="margin-bottom:18px;">
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
        <div style="background:linear-gradient(135deg,#2d0808,#1a0505);border:1px solid #b91c1c;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Борг по ЗП</p>
            <p style="color:#ef4444;font-size:22px;font-weight:900;line-height:1;">{{ number_format($tot['balance'], 0, '.', ' ') }} ₴</p>
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
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Борг по ЗП</th>
                    <th style="text-align:right;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Сума</th>
                    <th style="text-align:center;padding:12px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Виплата</th>
                </tr>
            </thead>
            <tbody>
                @php $prevGroup = null; @endphp
                @foreach($rows as $row)
                    @if($prevGroup !== $row['group'])
                        <tr><td colspan="9" style="padding:14px 16px 8px;background:#0c0c0e;border-top:1px solid #27272a;">
                            <span style="color:#a1a1aa;font-size:11px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;">{{ $row['group_label'] }}</span>
                        </td></tr>
                        @php $prevGroup = $row['group']; @endphp
                    @endif
                    <tr style="border-bottom:1px solid #1f1f22;">

                        <td style="padding:11px 16px;">
                            <div style="color:#f4f4f5;font-weight:600;">{{ $row['name'] }}</div>
                        </td>

                        <td style="padding:11px 16px;color:#a1a1aa;font-size:12px;">
                            {{ $row['position'] }}
                            @if($row['payment_type'] === 'per_month')
                                <span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#1e3a5f;color:#60a5fa;border-radius:4px;font-size:10px;font-weight:600;">оклад</span>
                            @endif
                        </td>

                        <td style="padding:11px 16px;text-align:right;color:#f4f4f5;font-weight:600;">
                            @if(!empty($row['breakdown']))
                                {{-- Курʼєр: наведи (або тапни на телефоні) — розшифровка по днях:
                                     базова + точки понад ліміт + дальня доставка. --}}
                                <span x-data
                                      x-tooltip="{ content: @js(implode('<br>', $row['breakdown'])), allowHTML: true, theme: $store.theme, maxWidth: 420 }"
                                      style="cursor:help;border-bottom:1px dotted #52525b;padding-bottom:1px;">
                                    {{ $row['salary'] > 0 ? number_format($row['salary'], 0, '.', ' ') . ' ₴' : '—' }}
                                </span>
                            @else
                                {{ $row['salary'] > 0 ? number_format($row['salary'], 0, '.', ' ') . ' ₴' : '—' }}
                            @endif
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
                        <td style="padding:11px 16px;text-align:right;color:{{ $row['balance'] > 0 ? '#ef4444' : '#3f3f46' }};font-weight:{{ $row['balance'] > 0 ? '700' : '400' }};">
                            {{ $row['balance'] > 0 ? number_format($row['balance'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                        <td style="padding:11px 16px;text-align:right;color:#34d399;font-weight:800;font-size:14px;">
                            {{ number_format($row['sum'], 0, '.', ' ') }} ₴
                        </td>
                        <td style="padding:11px 16px;text-align:center;">
                            @if($row['balance'] > 0)
                                <button type="button"
                                        wire:click="mountAction('pay', { record: {{ $row['id'] }} })"
                                        title="Виплатити борг"
                                        style="display:inline-flex;align-items:center;gap:5px;background:#052e16;border:1px solid #065f46;color:#34d399;padding:5px 12px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;transition:background 0.15s;"
                                        onmouseover="this.style.background='#065f46';"
                                        onmouseout="this.style.background='#052e16';">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:13px;height:13px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 12a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V12zm-12 0h.008v.008H6V12z" />
                                    </svg>
                                    Виплатити
                                </button>
                            @else
                                <span style="color:#3f3f46;font-size:11px;">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach

                @if(empty($rows))
                <tr><td colspan="9" style="padding:40px 20px;text-align:center;color:#52525b;font-size:13px;">Немає даних за обраний період.</td></tr>
                @endif
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #3f3f46;background:linear-gradient(135deg,#111113,#18181b);">
                    <td colspan="2" style="padding:14px 16px;color:#a1a1aa;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Разом</td>
                    <td style="padding:14px 16px;text-align:right;color:#f4f4f5;font-weight:900;font-size:14px;">{{ number_format($tot['salary'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#a78bfa;font-weight:900;font-size:14px;">{{ number_format($tot['bonus'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#f87171;font-weight:900;font-size:14px;">{{ number_format($tot['penalty'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#fbbf24;font-weight:900;font-size:14px;">{{ number_format($tot['compensation'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#ef4444;font-weight:900;font-size:14px;">{{ number_format($tot['balance'], 0, '.', ' ') }} ₴</td>
                    <td style="padding:14px 16px;text-align:right;color:#34d399;font-weight:900;font-size:18px;">{{ number_format($tot['sum'], 0, '.', ' ') }} ₴</td>
                    <td></td>
                </tr>

                @php
                    // Для строки "грн/порция" беремо ключ що відповідає поточному фільтру:
                    //  - all → 'all' (сума ФОПів усіх груп / порції періоду)
                    //  - kitchen / couriers / management / marketing / other → відповідна група
                    $cppKey = $groupFilter === 'all' ? 'all' : $groupFilter;
                    $cppData = $perPortion[$cppKey] ?? null;
                    $cpp     = $cppData['rate'] ?? 0;
                    $cppColor = '#27272a';
                    if ($cpp > 0) {
                        if ($cpp >= 80 && $cpp <= 90)  $cppColor = '#14b8a6';
                        elseif ($cpp < 120)            $cppColor = '#22c55e';
                        else                           $cppColor = '#ef4444';
                    }
                    $cppScope = $groupFilter === 'all' ? 'всі' : mb_strtolower($groups[$groupFilter] ?? $groupFilter);
                @endphp
                <tr style="background:#111113;border-top:1px solid #27272a;">
                    <td colspan="2" style="padding:12px 16px;color:#a1a1aa;font-size:12px;font-weight:600;">
                        ₴ / порція <span style="color:#3f3f46;font-weight:400;">({{ $cppScope }})</span>
                    </td>
                    <td colspan="4" style="padding:12px 16px;text-align:right;color:#52525b;font-size:11px;">
                        @if($cppData)
                            {{ number_format($cppData['fop'], 0, '.', ' ') }} ₴ / {{ $cppData['portions'] }} порц
                        @endif
                    </td>
                    <td style="padding:12px 16px;text-align:right;color:{{ $cppColor }};font-weight:800;font-size:16px;">
                        @if($cpp > 0)
                            {{ number_format($cpp, 2, '.', ' ') }} ₴
                        @else
                            <span style="color:#27272a;">–</span>
                        @endif
                    </td>
                </tr>
            </tfoot>
        </table>
        </div>
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
            Кухня — на порції наступного дня (готують сьогодні на завтра). Кур'єри — на порції того самого дня. Менеджмент / маркетинг — на всі порції періоду. «Всі» — сума ФОПів усіх груп ділиться на порції періоду.
        </p>
    </div>

</div>
</x-filament-panels::page>
