<x-filament-panels::page>
@push('styles')
<style>
    .fi-page-header,
    .fi-header { display: none !important; }
</style>
@endpush
<div class="space-y-5">

    {{-- ЗАГОЛОВОК --}}
    <div>
        <h1 style="font-size:24px;font-weight:800;color:#f4f4f5;line-height:1.2;">Логістика</h1>
        <p style="font-size:13px;color:#71717a;margin-top:2px;">Маршрути та витрати</p>
    </div>

    {{-- ПАНЕЛЬ КЕРУВАННЯ --}}
    <div style="background: linear-gradient(135deg, #18181b 0%, #1c1c1f 100%); border: 1px solid #3f3f46; border-radius: 16px; padding: 20px 24px;">
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">

            {{-- Фільтри --}}
            <div style="flex: 0 0 auto;">
                <form wire:submit.prevent style="display:flex; gap:10px; align-items:center;">
                    {{ $this->form }}
                </form>
            </div>

            <div style="flex: 1; min-width: 12px;"></div>

            {{-- Кнопки --}}
            <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">

                {{-- Синхронізація клієнтів --}}
                <button wire:click="mountAction('sync_clients')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:#1e3a5f;color:#60a5fa;border:1px solid #1e40af;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Клієнти
                </button>

                {{-- Відправити замовлення --}}
                <button wire:click="mountAction('push_orders')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:#3b2800;color:#fb923c;border:1px solid #92400e;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                    Замовлення →
                </button>

                {{-- Завантажити маршрути --}}
                <button wire:click="mountAction('pull_routes')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:#052e16;color:#34d399;border:1px solid #065f46;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498l4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 00-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0z"/></svg>
                    Маршрути ↓
                </button>

                {{-- Точки маршрутів --}}
                <button wire:click="mountAction('pull_route_details')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;font-size:13px;font-weight:700;border:none;cursor:pointer;transition:all .15s;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;box-shadow:0 0 16px #6d28d940;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    Точки ↓
                </button>

                {{-- Роздільник --}}
                <div style="width:1px;height:28px;background:#3f3f46;margin:0 2px;"></div>

                {{-- Вихідні кур'єрів --}}
                <button wire:click="mountAction('closed_slots')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;font-size:13px;font-weight:600;border:none;cursor:pointer;transition:all .15s;background:#3b2800;color:#fbbf24;border:1px solid #92400e;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                    Вихідні
                </button>

                {{-- Ставки --}}
                <button wire:click="mountAction('settings')"
                    style="display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;font-size:13px;font-weight:600;border:1px solid #3f3f46;cursor:pointer;transition:all .15s;background:#27272a;color:#a1a1aa;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    Ставки
                </button>

            </div>
        </div>
    </div>

    {{-- КАРТКИ ПІДСУМКІВ --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3">

        <div style="background:#18181b;border:1px solid #27272a;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Маршрутів</p>
            <p style="color:#fff;font-size:26px;font-weight:900;line-height:1;">{{ $totalRoutes }}</p>
        </div>

        <div style="background:#18181b;border:1px solid #1e3a5f;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Точок</p>
            <p style="color:#60a5fa;font-size:26px;font-weight:900;line-height:1;">{{ $totalStops }}</p>
        </div>

        <div style="background:#18181b;border:1px solid #2e1065;border-radius:14px;padding:16px 18px;">
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Пробіг кур'єрів</p>
            <p style="color:#a78bfa;font-size:26px;font-weight:900;line-height:1;">{{ $totalMileageKm }} <span style="font-size:13px;color:#52525b;">км</span></p>
        </div>

        <div style="background:linear-gradient(135deg,#052e16,#0a1f14);border:1px solid #065f46;border-radius:14px;padding:16px 18px;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;background:#34d39915;border-radius:50%;"></div>
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">ЗП кур'єрів</p>
            <p style="color:#34d399;font-size:22px;font-weight:900;line-height:1;">{{ number_format($totalCost, 0, '.', ' ') }} ₴</p>
            @if($totalAntCost > 0)
                <p style="color:#4b5563;font-size:11px;margin-top:4px;">АНТ: {{ number_format($totalAntCost, 0, '.', ' ') }} ₴</p>
            @endif
        </div>

        <div style="background:linear-gradient(135deg,#1f1d0a,#1c1812);border:1px solid #92400e;border-radius:14px;padding:16px 18px;position:relative;overflow:hidden;">
            <div style="position:absolute;top:-10px;right:-10px;width:60px;height:60px;background:#fbbf2415;border-radius:50%;"></div>
            <p style="color:#71717a;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px;">Компенсація</p>
            <p style="color:#fbbf24;font-size:22px;font-weight:900;line-height:1;">{{ number_format($totalMileageComp, 0, '.', ' ') }} ₴</p>
            <p style="color:#4b5563;font-size:11px;margin-top:4px;">пальне + амортизація</p>
        </div>

    </div>

    @if($totalRoutes > 0)

    {{-- ТАБЛИЦЯ --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:1px solid #27272a;background:#111113;">
                    <th style="text-align:left;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">#</th>
                    <th style="text-align:left;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Водій</th>
                    <th style="text-align:left;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Авто</th>
                    <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Точок</th>
                    <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Виїзд</th>
                    <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Повернення</th>
                    <th style="text-align:right;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Ставка</th>
                </tr>
            </thead>
            <tbody>
                @foreach($routes as $i => $route)
                @php
                    $stops = $route['count_comps'] ?? 0;
                    $baseStops = (int) (\App\Models\Setting::where('key','courier_base_stops')->value('value') ?: 12);
                    $isOverLimit = $stops > $baseStops;
                    $isEven = $i % 2 === 0;
                @endphp
                <tr style="border-bottom:1px solid #1f1f22;{{ $isEven ? '' : 'background:#111113;' }}transition:background .1s;"
                    onmouseover="this.style.background='#ffffff08'" onmouseout="this.style.background='{{ $isEven ? 'transparent' : '#111113' }}'">

                    <td style="padding:12px 16px;color:#71717a;font-family:monospace;font-size:12px;">
                        {{ $route['ant_route_num'] ?? '—' }}
                    </td>

                    <td style="padding:12px 16px;">
                        <span style="color:#f4f4f5;font-weight:600;">{{ $route['driver_name'] ?? '—' }}</span>
                        @if(isset($route['employee']) && $route['employee'])
                            <span style="display:inline-block;margin-left:6px;padding:1px 6px;background:#1e3a5f;color:#60a5fa;border-radius:4px;font-size:10px;font-weight:600;">✓</span>
                        @endif
                    </td>

                    <td style="padding:12px 16px;">
                        <div style="color:#d4d4d8;">{{ $route['model_auto'] ?: $route['auto_name'] ?: '—' }}</div>
                        @if($route['registration_number'])
                            <div style="color:#52525b;font-size:11px;margin-top:2px;">{{ $route['registration_number'] }}</div>
                        @endif
                    </td>

                    <td style="padding:12px 16px;text-align:center;">
                        <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700;
                            {{ $isOverLimit ? 'background:#431407;color:#fb923c;' : 'background:#1e3a5f;color:#60a5fa;' }}">
                            {{ $stops }}
                            @if($isOverLimit)
                                <span style="font-size:10px;opacity:.8;">+{{ $stops - $baseStops }}</span>
                            @endif
                        </span>
                    </td>

                    <td style="padding:12px 16px;text-align:center;color:#71717a;font-size:12px;">
                        @if($route['route_time_b'])
                            {{ \Illuminate\Support\Str::afterLast($route['route_time_b'], ' ') }}
                        @else —
                        @endif
                    </td>

                    <td style="padding:12px 16px;text-align:center;color:#71717a;font-size:12px;">
                        @if($route['route_time_e'])
                            {{ \Illuminate\Support\Str::afterLast($route['route_time_e'], ' ') }}
                        @else —
                        @endif
                    </td>

                    <td style="padding:12px 16px;text-align:right;">
                        <span style="color:#34d399;font-weight:700;font-size:14px;">
                            {{ number_format($route['calculated_cost'], 0, '.', ' ') }} ₴
                        </span>
                        @if($route['ant_cost_route'] > 0)
                            <div style="color:#3f3f46;font-size:11px;margin-top:2px;">АНТ: {{ number_format($route['ant_cost_route'], 0, '.', ' ') }} ₴</div>
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr style="border-top:2px solid #3f3f46;background:linear-gradient(135deg,#111113,#18181b);">
                    <td colspan="3" style="padding:14px 16px;color:#a1a1aa;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Разом</td>
                    <td style="padding:14px 16px;text-align:center;color:#60a5fa;font-weight:900;font-size:15px;">{{ $totalStops }}</td>
                    <td colspan="2"></td>
                    <td style="padding:14px 16px;text-align:right;color:#34d399;font-weight:900;font-size:18px;">{{ number_format($totalCost, 0, '.', ' ') }} ₴</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @else
    <div style="text-align:center;padding:60px 20px;color:#3f3f46;">
        <div style="font-size:48px;margin-bottom:16px;">🚚</div>
        <p style="font-size:16px;color:#52525b;font-weight:600;">Немає даних маршрутів за цю дату</p>
        <p style="font-size:13px;color:#3f3f46;margin-top:6px;">Натисни «Точки ↓» щоб завантажити з АНТ</p>
    </div>
    @endif

    {{-- ПРОБІГ КУР'ЄРІВ --}}
    <div style="background:#18181b;border:1px solid #27272a;border-radius:16px;overflow:hidden;">
        <div style="padding:18px 22px;border-bottom:1px solid #27272a;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
            <div>
                <h2 style="font-size:16px;font-weight:800;color:#f4f4f5;margin:0;">Пробіг кур'єрів</h2>
                <p style="font-size:12px;color:#71717a;margin-top:2px;">На обрану дату. Вноси одометр (поч/кін) та пальне — компенсація рахується автоматично.</p>
            </div>
            <div style="display:flex;gap:14px;font-size:12px;color:#71717a;">
                <span>Амортизація: <b style="color:#fbbf24;">{{ rtrim(rtrim(number_format($amortPerKm, 2, '.', ''), '0'), '.') }} ₴/км</b></span>
            </div>
        </div>

        @if(count($mileageRows) === 0)
            <div style="text-align:center;padding:40px 20px;color:#52525b;font-size:13px;">
                Немає активних кур'єрів.
            </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #27272a;background:#111113;">
                        <th style="text-align:left;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Кур'єр</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;width:130px;">Поч. км</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;width:130px;">Кін. км</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;">Пробіг</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;width:130px;">Ціна літра ₴</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;">Пальне ₴</th>
                        <th style="text-align:center;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 12px;">Амортизація</th>
                        <th style="text-align:right;color:#52525b;font-size:10px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 16px;">Компенсація</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($mileageRows as $i => $row)
                    @php
                        $isEven = $i % 2 === 0;
                        $eid    = $row['employee_id'];
                    @endphp
                    <tr style="border-bottom:1px solid #1f1f22;{{ $isEven ? '' : 'background:#111113;' }}">
                        <td style="padding:10px 16px;">
                            <div style="color:#f4f4f5;font-weight:600;">{{ $row['name'] }}</div>
                            <div style="color:#52525b;font-size:11px;margin-top:2px;">
                                @if($row['consumption'] > 0)
                                    Витрата: {{ rtrim(rtrim(number_format($row['consumption'], 2, '.', ''), '0'), '.') }} л/100 км
                                @else
                                    <span style="color:#f87171;">Витрата не задана</span>
                                @endif
                            </div>
                        </td>

                        <td style="padding:8px 12px;text-align:center;">
                            <input type="number"
                                   value="{{ $row['start_km'] }}"
                                   wire:change="saveMileage({{ $eid }}, 'start_km', $event.target.value)"
                                   placeholder="—"
                                   style="width:100px;background:#27272a;border:1px solid #3f3f46;border-radius:8px;padding:6px 10px;color:#f4f4f5;font-size:13px;text-align:center;outline:none;"
                                   onfocus="this.style.borderColor='#3b82f6'"
                                   onblur="this.style.borderColor='#3f3f46'">
                        </td>

                        <td style="padding:8px 12px;text-align:center;">
                            <input type="number"
                                   value="{{ $row['end_km'] }}"
                                   wire:change="saveMileage({{ $eid }}, 'end_km', $event.target.value)"
                                   placeholder="—"
                                   style="width:100px;background:#27272a;border:1px solid #3f3f46;border-radius:8px;padding:6px 10px;color:#f4f4f5;font-size:13px;text-align:center;outline:none;"
                                   onfocus="this.style.borderColor='#3b82f6'"
                                   onblur="this.style.borderColor='#3f3f46'">
                        </td>

                        <td style="padding:8px 12px;text-align:center;">
                            <span style="{{ $row['km'] > 0 ? 'color:#a78bfa;font-weight:700;' : 'color:#3f3f46;' }}">
                                {{ $row['km'] > 0 ? $row['km'] . ' км' : '—' }}
                            </span>
                        </td>

                        <td style="padding:8px 12px;text-align:center;">
                            <input type="number" step="0.01"
                                   value="{{ $row['fuel_price_per_liter'] > 0 ? $row['fuel_price_per_liter'] : '' }}"
                                   wire:change="saveMileage({{ $eid }}, 'fuel_price_per_liter', $event.target.value)"
                                   placeholder="0"
                                   style="width:100px;background:#27272a;border:1px solid #3f3f46;border-radius:8px;padding:6px 10px;color:#fb923c;font-size:13px;text-align:center;outline:none;font-weight:600;"
                                   onfocus="this.style.borderColor='#3b82f6'"
                                   onblur="this.style.borderColor='#3f3f46'">
                        </td>

                        <td style="padding:10px 12px;text-align:center;color:#fb923c;font-weight:600;">
                            @if($row['fuel_cost'] > 0)
                                {{ number_format($row['fuel_cost'], 0, '.', ' ') }} ₴
                                <div style="color:#52525b;font-size:10px;margin-top:2px;">
                                    {{ rtrim(rtrim(number_format($row['liters_used'], 2, '.', ''), '0'), '.') }} л
                                </div>
                            @else
                                <span style="color:#3f3f46;">—</span>
                            @endif
                        </td>

                        <td style="padding:10px 12px;text-align:center;color:#fbbf24;font-weight:600;">
                            {{ $row['amortization'] > 0 ? number_format($row['amortization'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>

                        <td style="padding:10px 16px;text-align:right;color:#34d399;font-weight:800;font-size:14px;">
                            {{ $row['compensation'] > 0 ? number_format($row['compensation'], 0, '.', ' ') . ' ₴' : '—' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr style="border-top:2px solid #3f3f46;background:linear-gradient(135deg,#111113,#18181b);">
                        <td style="padding:14px 16px;color:#a1a1aa;font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.05em;">Разом</td>
                        <td colspan="2"></td>
                        <td style="padding:14px 12px;text-align:center;color:#a78bfa;font-weight:900;font-size:15px;">{{ $totalMileageKm }} км</td>
                        <td></td>
                        <td style="padding:14px 12px;text-align:center;color:#fb923c;font-weight:900;font-size:15px;">{{ number_format($totalMileageFuel, 0, '.', ' ') }} ₴</td>
                        <td style="padding:14px 12px;text-align:center;color:#fbbf24;font-weight:900;font-size:15px;">{{ number_format($totalMileageAmort, 0, '.', ' ') }} ₴</td>
                        <td style="padding:14px 16px;text-align:right;color:#34d399;font-weight:900;font-size:18px;">{{ number_format($totalMileageComp, 0, '.', ' ') }} ₴</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
