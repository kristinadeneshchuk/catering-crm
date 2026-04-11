<x-filament-panels::page>
@push('styles')
<style>
    .fi-page-header { display: none !important; }
    .attend-row { transition: background .15s; }
    .attend-row:hover { background: rgba(255,255,255,.04) !important; }
    .attend-check { width: 22px; height: 22px; border-radius: 6px; cursor: pointer; accent-color: #22c55e; }
    .attend-rate {
        width: 110px; background: #27272a; border: 1px solid #3f3f46;
        border-radius: 8px; padding: 6px 10px; color: #f4f4f5;
        font-size: 14px; font-weight: 600; outline: none;
        transition: border-color .15s;
    }
    .attend-rate:focus { border-color: #22c55e; }
    .attend-rate:disabled { opacity: .4; }
</style>
@endpush

<div class="space-y-5">

    {{-- ДАТА СПРАВА --}}
    <div style="display:flex;justify-content:flex-end;">
        <input type="date" wire:model.live="date" wire:change="loadAttendance"
            style="background:#27272a;border:1px solid #3f3f46;border-radius:10px;padding:9px 14px;color:#f4f4f5;font-size:14px;font-weight:600;outline:none;">
    </div>

    {{-- СТАТУС ДЕНЬ --}}
    @if($dailyTotal > 0)
    <div style="background:linear-gradient(135deg,#052e16,#0a1f14);border:1px solid #065f46;border-radius:14px;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;gap:16px;">
        <div style="display:flex;align-items:center;gap:12px;">
            <div style="width:40px;height:40px;background:#14532d;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#22c55e" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p style="color:#22c55e;font-weight:700;font-size:15px;line-height:1.2;">Табель заповнено</p>
                <p style="color:#4ade80;font-size:12px;opacity:.7;margin-top:2px;">{{ \Carbon\Carbon::parse($date)->isoFormat('D MMMM Y') }}</p>
            </div>
        </div>
        <div style="text-align:right;">
            <p style="color:#4ade80;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.6;">Разом нараховано</p>
            <p style="color:#22c55e;font-size:24px;font-weight:900;line-height:1.1;">{{ number_format($dailyTotal, 0, '.', ' ') }} ₴</p>
        </div>
    </div>
    @else
    <div style="background:#1c1400;border:1px solid #78350f;border-radius:14px;padding:14px 18px;display:flex;align-items:center;gap:12px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="#f59e0b" stroke-width="2" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
        <p style="color:#fbbf24;font-size:13px;font-weight:500;">За цей день ще немає нарахувань. Відмітьте тих, хто працював, та збережіть.</p>
    </div>
    @endif

    {{-- ТАБЛИЦЯ --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#111113;border-bottom:1px solid #27272a;">
                    <th style="text-align:left;padding:13px 20px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Співробітник</th>
                    <th style="text-align:left;padding:13px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Посада</th>
                    <th style="text-align:left;padding:13px 16px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Ставка за день</th>
                    <th style="text-align:center;padding:13px 20px;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;">Вийшов</th>
                </tr>
            </thead>
            <tbody>
                @forelse($attendance as $id => $data)
                @php
                    $posColors = [
                        'cook'    => ['bg'=>'#3b1a00','color'=>'#fb923c'],
                        'manager' => ['bg'=>'#052e16','color'=>'#34d399'],
                        'packer'  => ['bg'=>'#1e1b4b','color'=>'#a78bfa'],
                        'cleaner' => ['bg'=>'#1a1a2e','color'=>'#818cf8'],
                        'admin'   => ['bg'=>'#1e3a5f','color'=>'#60a5fa'],
                    ];
                    $pc = $posColors[$data['position']] ?? ['bg'=>'#27272a','color'=>'#a1a1aa'];
                    $posLabels = ['cook'=>'Кухар','manager'=>'Менеджер','packer'=>'Пакувальник','cleaner'=>'Прибиральниця','admin'=>'Адміністратор','courier'=>'Кур\'єр'];
                    $posLabel = $posLabels[$data['position']] ?? $data['position'];
                @endphp
                <tr class="attend-row" style="border-bottom:1px solid #1f1f22;{{ $data['present'] ? 'background:rgba(34,197,94,.06);' : '' }}">

                    <td style="padding:14px 20px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:34px;height:34px;border-radius:50%;background:#27272a;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#a1a1aa;flex-shrink:0;">
                                {{ mb_substr($data['name'], 0, 1) }}
                            </div>
                            <span style="color:#f4f4f5;font-weight:600;font-size:14px;">{{ $data['name'] }}</span>
                        </div>
                    </td>

                    <td style="padding:14px 16px;">
                        <span style="padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;background:{{ $pc['bg'] }};color:{{ $pc['color'] }};">
                            {{ $posLabel }}
                        </span>
                    </td>

                    <td style="padding:14px 16px;">
                        @if($data['position'] !== 'courier')
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="color:#52525b;font-size:13px;">₴</span>
                            <input type="number" wire:model="attendance.{{ $id }}.rate"
                                class="attend-rate"
                                {{ !$data['present'] ? 'disabled' : '' }}>
                        </div>
                        @else
                            @if(($data['courier_earned'] ?? 0) > 0)
                                <span style="color:#60a5fa;font-weight:700;font-size:14px;">{{ number_format($data['courier_earned'], 0, '.', ' ') }} ₴</span>
                                <span style="display:block;color:#3f3f46;font-size:10px;margin-top:2px;">з маршрутів</span>
                            @else
                                <span style="color:#3f3f46;font-size:13px;">немає маршрутів</span>
                            @endif
                        @endif
                    </td>

                    <td style="padding:14px 20px;text-align:center;">
                        <label style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:8px;cursor:pointer;
                            {{ $data['present'] ? 'background:#14532d;border:2px solid #22c55e;' : 'background:#27272a;border:2px solid #3f3f46;' }}
                            transition:all .15s;">
                            <input type="checkbox" wire:model.live="attendance.{{ $id }}.present"
                                style="position:absolute;opacity:0;width:0;height:0;">
                            @if($data['present'])
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="#22c55e" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            @endif
                        </label>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="4" style="padding:48px;text-align:center;color:#52525b;">
                        Співробітників не знайдено. Перевірте чи вони активні.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- КНОПКА ЗБЕРЕЖЕННЯ --}}
    <button wire:click="save"
        style="width:100%;padding:16px;border-radius:14px;border:none;cursor:pointer;font-size:15px;font-weight:700;letter-spacing:.02em;
        background:linear-gradient(135deg,#166534,#14532d);color:#86efac;box-shadow:0 0 16px rgba(20,83,45,.5);transition:all .15s;"
        onmouseover="this.style.boxShadow='0 0 24px rgba(20,83,45,.7)'"
        onmouseout="this.style.boxShadow='0 0 16px rgba(20,83,45,.5)'">
        Зберегти табель та нарахувати гроші на баланси
    </button>

</div>
</x-filament-panels::page>
