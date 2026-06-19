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
    .pr-row-clickable:hover { background: #ffffff08 !important; cursor: pointer; }
    .pr-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(0,0,0,.7);
        z-index: 50;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        backdrop-filter: blur(4px);
    }
    .pr-modal {
        background: #18181b;
        border: 1px solid #27272a;
        border-radius: 16px;
        max-width: 720px; width: 100%;
        max-height: 90vh;
        overflow-y: auto;
    }
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
            <p style="font-size:13px;color:#71717a;margin-top:2px;">Нарахування за період · клік на рядок — історія + виплата</p>
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
                    <tr class="pr-row-clickable" style="border-bottom:1px solid #1f1f22;transition:background .1s;"
                        wire:click="openHistory({{ $row['id'] }})">

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
    <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;padding:18px 22px;">
        <h2 style="font-size:14px;font-weight:800;color:#f4f4f5;margin:0 0 4px;">Грн / порція по групах</h2>
        <p style="font-size:12px;color:#71717a;margin:0 0 14px;">
            Кухня — на порції наступного дня (готують сьогодні на завтра). Кур'єри — на порції того самого дня. Менеджмент / маркетинг — на всі порції періоду.
        </p>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
            @foreach(['kitchen','couriers','management','marketing','other'] as $g)
                @if(isset($perPortion[$g]))
                <div style="background:#111113;border:1px solid #27272a;border-radius:12px;padding:14px 16px;">
                    <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">{{ $groups[$g] ?? $g }}</p>
                    <p style="color:#34d399;font-size:22px;font-weight:900;line-height:1;">{{ $perPortion[$g]['rate'] }} ₴</p>
                    <p style="color:#52525b;font-size:11px;margin-top:6px;">
                        {{ number_format($perPortion[$g]['fop'], 0, '.', ' ') }} ₴ / {{ $perPortion[$g]['portions'] }} порц
                    </p>
                </div>
                @endif
            @endforeach
        </div>
    </div>

    {{-- МОДАЛКА ІСТОРІЇ + ВИПЛАТА --}}
    @if($selectedEmployeeId)
        @php $history = $this->getEmployeeHistory($selectedEmployeeId); @endphp
        <div class="pr-modal-overlay" wire:click.self="closeHistory">
            <div class="pr-modal">
                <div style="padding:20px 24px;border-bottom:1px solid #27272a;display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <h3 style="font-size:18px;font-weight:800;color:#f4f4f5;margin:0;">{{ $history['employee']['name'] }}</h3>
                        <p style="color:#71717a;font-size:12px;margin-top:2px;">Поточний баланс: <b style="color:{{ $history['employee']['balance'] > 0 ? '#34d399' : ($history['employee']['balance'] < 0 ? '#f87171' : '#a1a1aa') }};">{{ number_format($history['employee']['balance'], 2, '.', ' ') }} ₴</b></p>
                    </div>
                    <button wire:click="closeHistory" style="background:transparent;border:none;color:#71717a;font-size:24px;cursor:pointer;line-height:1;">×</button>
                </div>

                {{-- Виплата --}}
                <div style="padding:18px 24px;background:#111113;border-bottom:1px solid #27272a;">
                    <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap;">
                        <div style="flex:1;min-width:130px;">
                            <label style="display:block;color:#71717a;font-size:11px;font-weight:700;margin-bottom:4px;letter-spacing:.05em;text-transform:uppercase;">Сума, ₴</label>
                            <input type="number" step="0.01" wire:model="payoutAmount" class="pr-input" style="width:100%;">
                        </div>
                        <div style="flex:1;min-width:160px;">
                            <label style="display:block;color:#71717a;font-size:11px;font-weight:700;margin-bottom:4px;letter-spacing:.05em;text-transform:uppercase;">Рахунок списання</label>
                            <select wire:model="payoutAccountId" class="pr-input" style="width:100%;cursor:pointer;">
                                <option value="">— виберіть —</option>
                                @foreach($this->getAccountOptions() as $accId => $accName)
                                    <option value="{{ $accId }}">{{ $accName }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div style="flex:2;min-width:180px;">
                            <label style="display:block;color:#71717a;font-size:11px;font-weight:700;margin-bottom:4px;letter-spacing:.05em;text-transform:uppercase;">Коментар</label>
                            <input type="text" wire:model="payoutComment" class="pr-input" style="width:100%;" placeholder="напр. зарплата за червень">
                        </div>
                        <button wire:click="payout"
                                style="padding:9px 18px;border-radius:10px;background:#065f46;color:#fff;border:none;font-weight:700;font-size:13px;cursor:pointer;">
                            Виплатити
                        </button>
                    </div>
                </div>

                {{-- Стрічка подій --}}
                <div style="padding:8px 0;">
                    @forelse($history['events'] as $ev)
                        @php
                            $color = match($ev['kind']) {
                                'shift'   => '#f4f4f5',
                                'penalty' => '#f87171',
                                'comp'    => '#fbbf24',
                                'payout'  => '#60a5fa',
                                default   => '#a1a1aa',
                            };
                            $sign = $ev['amount'] >= 0 ? '+' : '−';
                            $amt  = number_format(abs($ev['amount']), 2, '.', ' ');
                            $dateStr = $ev['date'] instanceof \Carbon\Carbon ? $ev['date']->format('d.m.Y') : \Carbon\Carbon::parse($ev['date'])->format('d.m.Y');
                        @endphp
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 24px;border-bottom:1px solid #1f1f22;">
                            <div style="flex:1;">
                                <div style="color:#f4f4f5;font-size:13px;">{{ $ev['label'] }}</div>
                                <div style="color:#52525b;font-size:11px;margin-top:2px;">{{ $dateStr }}</div>
                            </div>
                            <div style="color:{{ $color }};font-weight:700;font-size:14px;">
                                {{ $sign }}{{ $amt }} ₴
                            </div>
                        </div>
                    @empty
                        <div style="padding:30px 24px;text-align:center;color:#52525b;font-size:13px;">Подій ще немає.</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif

</div>
</x-filament-panels::page>
