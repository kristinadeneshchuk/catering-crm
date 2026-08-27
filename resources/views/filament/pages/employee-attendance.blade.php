<x-filament-panels::page>
@push('styles')
<style>
    .emp-wrap { font-family: -apple-system, BlinkMacSystemFont, 'Inter', 'Segoe UI', sans-serif; }
    .emp-wrap * { box-sizing: border-box; }
    .emp-btn { cursor: pointer; transition: opacity .15s; }
    .emp-btn:hover { opacity: .75; }
    .emp-cell-btn { border: none; cursor: pointer; transition: transform .12s; background: transparent; }
    .emp-cell-btn:hover { transform: scale(1.12); }
    .emp-slot-item:hover { background: #27272a !important; }
    [x-cloak] { display: none !important; }
    .emp-duty-star { transition: transform .12s ease, background .15s, box-shadow .15s; }
    .emp-duty-star:hover { transform: scale(1.18); }
    .emp-duty-star.is-active { animation: emp-duty-pulse 2s ease-in-out infinite; }
    @keyframes emp-duty-pulse {
        0%, 100% { box-shadow: 0 0 8px rgba(245, 158, 11, .6); }
        50%      { box-shadow: 0 0 14px rgba(245, 158, 11, .95); }
    }
    .fi-page-header { display: none !important; }

    .emp-date-input {
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
    .emp-date-input:focus { border-color: #3b82f6; }

    /* Sticky left column */
    .emp-sticky {
        position: sticky;
        left: 0;
        z-index: 2;
        background: #18181b;
    }
    .emp-sticky-head {
        position: sticky;
        left: 0;
        z-index: 3;
        background: #111113;
    }
    /* Shadow separator after sticky column */
    .emp-sticky::after,
    .emp-sticky-head::after {
        content: '';
        position: absolute;
        top: 0; right: -8px; bottom: 0;
        width: 8px;
        background: linear-gradient(to right, rgba(0,0,0,.25), transparent);
        pointer-events: none;
    }
</style>
@endpush

@php
    $data   = $this->getData();
    $dates  = $this->getDates();
    $today  = $data['today'];
    $ukDays = ['Пн','Вт','Ср','Чт','Пт','Сб','Нд'];
@endphp

<div class="emp-wrap">

    {{-- ШАПКА --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <h1 style="font-size:20px;font-weight:700;color:#f4f4f5;margin:0;">Табель змін</h1>

        {{-- Вибір діапазону дат --}}
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="color:#71717a;font-size:13px;">Період:</span>
            <input type="date" wire:model.live="startDate" class="emp-date-input">
            <span style="color:#52525b;">→</span>
            <input type="date" wire:model.live="endDate" class="emp-date-input">
        </div>
    </div>

    {{-- СТАТИСТИКА --}}
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;">
        <div style="background:#18181b;border-radius:14px;padding:16px 20px;border:1px solid #27272a;">
            <p style="color:#52525b;font-size:12px;margin:0 0 6px;font-weight:500;">Змін за період</p>
            <p style="color:#f4f4f5;font-size:26px;font-weight:700;margin:0;line-height:1.2;">{{ $data['stats']['shifts'] }}</p>
        </div>
        <div style="background:#18181b;border-radius:14px;padding:16px 20px;border:1px solid #27272a;">
            <p style="color:#52525b;font-size:12px;margin:0 0 6px;font-weight:500;">Нараховано зарплати</p>
            <p style="color:#f59e0b;font-size:26px;font-weight:700;margin:0;line-height:1.2;">{{ number_format($data['stats']['salary'], 0, '.', ' ') }} ₴</p>
        </div>
        <div style="background:#18181b;border-radius:14px;padding:16px 20px;border:1px solid #27272a;">
            <p style="color:#52525b;font-size:12px;margin:0 0 6px;font-weight:500;">Не вийшли сьогодні</p>
            <p style="color:{{ $data['stats']['absent_today'] > 0 ? '#f87171' : '#f4f4f5' }};font-size:26px;font-weight:700;margin:0;line-height:1.2;">
                {{ $data['stats']['absent_today'] }}
            </p>
        </div>
    </div>

    {{-- ФІЛЬТРИ РОЛЕЙ --}}
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;">
        @foreach(['all' => 'Всі ролі', 'cook' => 'Кухарі', 'courier' => "Кур'єри", 'manager' => 'Менеджери'] as $key => $label)
        <button wire:click="$set('roleFilter', '{{ $key }}')" class="emp-btn"
            style="padding:7px 16px;border-radius:20px;font-size:13px;font-weight:{{ $roleFilter === $key ? '600' : '500' }};
                   border:1.5px solid {{ $roleFilter === $key ? '#3b82f6' : '#3f3f46' }};
                   background:{{ $roleFilter === $key ? '#1e3a5f' : 'transparent' }};
                   color:{{ $roleFilter === $key ? '#60a5fa' : '#71717a' }};">
            {{ $label }}
        </button>
        @endforeach
    </div>

    {{-- ТАБЛИЦЯ зі sticky лівою колонкою --}}
    <div style="background:#18181b;border-radius:16px;border:1px solid #27272a;overflow:hidden;margin-bottom:14px;">
        <div style="overflow-x:auto;">
        <table style="width:max-content;min-width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#111113;border-bottom:1px solid #27272a;">
                    {{-- Фіксована колонка --}}
                    <th class="emp-sticky-head"
                        style="text-align:left;padding:14px 20px;color:#52525b;font-size:12px;font-weight:500;min-width:220px;border-right:1px solid #27272a;">
                        Співробітник
                    </th>
                    {{-- Колонки дат --}}
                    @foreach($dates as $date)
                    @php
                        $d       = \Carbon\Carbon::parse($date);
                        $isToday = $date === $today;
                        $dayIdx  = ($d->dayOfWeek + 6) % 7; // Пн=0
                    @endphp
                    <th style="text-align:center;padding:14px 6px;min-width:56px;">
                        <span style="font-size:11px;font-weight:{{ $isToday ? '700' : '500' }};
                                     color:{{ $isToday ? '#60a5fa' : ($d->isWeekend() ? '#52525b' : '#71717a') }};
                                     display:inline-flex;flex-direction:column;align-items:center;gap:2px;">
                            <span>{{ $ukDays[$dayIdx] }}</span>
                            <span style="font-size:13px;font-weight:{{ $isToday ? '700' : '600' }};">{{ $d->day }}</span>
                            @if($isToday)
                            <span style="display:inline-block;width:4px;height:4px;border-radius:50%;background:#3b82f6;"></span>
                            @endif
                        </span>

                        {{-- День настав, а плани ще не підтверджені. Автоматично
                             перетворювати план у факт не можна: заплатили б тому,
                             хто не вийшов. Тому — кнопка на весь день, а тих, хто
                             не вийшов, менеджер знімає кліком по клітинці. --}}
                        @php $plannedHere = (int) ($data['planned_by_date'][$date] ?? 0); @endphp
                        @if($plannedHere > 0 && $date <= $today)
                        <button wire:click="confirmDay('{{ $date }}')"
                                wire:confirm="Підтвердити {{ $plannedHere }} запланован{{ $plannedHere === 1 ? 'ий вихід' : 'і виходи' }} за {{ \Carbon\Carbon::parse($date)->format('d.m') }}? Нарахується ставка."
                                x-tooltip="{ content: @js('Підтверджує всі заплановані виходи за цей день (' . $plannedHere . '): план стає фактом і людям нараховуються ставки. Хто не вийшов — зніми клік по його клітинці ДО підтвердження.'), theme: $store.theme }"
                                style="display:block;margin:4px auto 0;padding:1px 6px;border-radius:6px;cursor:pointer;
                                       background:#052e16;border:1px solid #16a34a;color:#4ade80;
                                       font-size:10px;font-weight:700;line-height:1.6;white-space:nowrap;">
                            ✓ {{ $plannedHere }}
                        </button>
                        @endif
                    </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($data['rows'] as $row)
                @php
                    $words       = array_filter(explode(' ', $row['name']));
                    $initials    = collect($words)->take(2)->map(fn($w) => mb_strtoupper(mb_substr($w,0,1)))->join('');
                    $avatarBg    = $row['is_kitchen'] ? '#14532d' : '#1e3a5f';
                    $avatarColor = $row['is_kitchen'] ? '#22c55e' : '#60a5fa';
                @endphp
                <tr style="border-bottom:1px solid #1f1f22;">
                    {{-- Фіксована колонка --}}
                    <td class="emp-sticky" style="padding:12px 20px;border-right:1px solid #27272a;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:38px;height:38px;border-radius:50%;background:{{ $avatarBg }};
                                        display:flex;align-items:center;justify-content:center;
                                        font-size:12px;font-weight:700;color:{{ $avatarColor }};
                                        flex-shrink:0;letter-spacing:.3px;">
                                {{ $initials }}
                            </div>
                            <div>
                                <p style="color:#f4f4f5;font-weight:600;font-size:14px;margin:0;line-height:1.3;white-space:nowrap;">
                                    {{ $row['name'] }}
                                </p>
                                <p style="color:#71717a;font-size:12px;margin:0;line-height:1.4;white-space:nowrap;">
                                    {{ $row['position_label'] }} · {{ number_format($row['base_rate'], 0, '.', ' ') }} ₴/зміну
                                </p>
                                @if($row['absent_today'])
                                <p style="color:#f87171;font-size:11px;margin:2px 0 0;white-space:nowrap;display:flex;align-items:center;gap:3px;">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                    </svg>
                                    Не вийшов сьогодні
                                </p>
                                @endif
                            </div>
                        </div>
                    </td>

                    {{-- Клітинки днів --}}
                    @foreach($dates as $date)
                    @php
                        $dayInfo = $row['days'][$date] ?? ['status' => 'future', 'is_duty' => false];
                        $status  = $dayInfo['status'];
                        $isDuty  = $dayInfo['is_duty'];
                        $isHalf  = $dayInfo['is_half'] ?? false;
                        $isCourier = $row['position'] === 'courier';
                        $isPlan  = $dayInfo['is_planned'] ?? false;
                        $slot    = $dayInfo['slot'] ?? null;
                        // Для курʼєра терміни інші: 1 круг = 2 виїзди, ½ = 1 виїзд.
                        $tipFull = $isCourier ? 'Зараз: 2 виїзди (ранок + вечір). Клік — залишити 1 виїзд (пів зміни).' : 'Зараз: повна зміна, нараховано повну ставку. Клік — зробити пів зміни (половина ставки).';
                        $tipHalf = $isCourier ? 'Зараз: 1 виїзд (пів зміни). Клік — прибрати зміну зовсім, ставка зніметься.' : 'Зараз: пів зміни (половина ставки). Клік — прибрати зміну зовсім, ставка зніметься.';
                    @endphp
                    <td style="text-align:center;padding:6px 4px;">
                        <div style="position:relative;display:inline-block;width:34px;height:34px;">
                            @if($isCourier)
                                {{-- Курʼєр працює виїздами: ранок, вечір або обидва.
                                     Циклом кліків це не вибереш, тому — меню.
                                     Меню position:fixed, інакше горизонтальний скрол
                                     таблиці його обріже. --}}
                                @php
                                    $face = match ($slot) {
                                        'morning' => '☀',
                                        'evening' => '☾',
                                        default   => $status === 'present' ? '✓' : '–',
                                    };
                                    $fillC = $status === 'present' ? '#1e3a5f' : 'transparent';
                                @endphp
                                <div x-data="{ open: false, mx: 0, my: 0 }"
                                     @keydown.escape.window="open = false"
                                     style="display:inline-block;">
                                    <button type="button" class="emp-cell-btn" data-slot-trigger
                                        @click="const r = $el.getBoundingClientRect();
                                                mx = Math.min(Math.max(r.left + r.width / 2, 100), window.innerWidth - 100);
                                                my = r.bottom + 6 + (r.bottom + 210 > window.innerHeight ? -(r.height + 222) : 0);
                                                open = ! open"
                                        title="{{ $status === 'present' ? ($isPlan ? 'План: ' : '') . ($dayInfo['slot_label'] ?? '') . ' · ' . ($dayInfo['rate'] ?? 0) . ' ₴' : 'Поставити зміну' }}"
                                        style="width:34px;height:34px;border-radius:50%;
                                               background:{{ $isPlan ? 'transparent' : $fillC }};
                                               border:{{ $isPlan ? '2px dashed #60a5fa' : 'none' }};
                                               display:inline-flex;align-items:center;justify-content:center;
                                               color:{{ $status === 'present' ? '#60a5fa' : '#3f3f46' }};
                                               font-size:{{ $slot === 'full' || $slot === null ? '15px' : '14px' }};
                                               font-weight:600;line-height:1;">
                                        {{ $face }}
                                    </button>

                                    {{-- Меню виносимо в <body>. Всередині таблиці воно
                                         йшло під сусідні рядки: наступні клітинки
                                         малюються пізніше і мають свій фон, а z-index
                                         зі статичного style губився — Alpine перетирає
                                         атрибут своїм :style. Тут переліку стилів один,
                                         і перекрити меню вже нема кому. --}}
                                    <template x-teleport="body">
                                    <div x-show="open" x-cloak
                                         {{-- Клік по самій кнопці не гасимо: вона вже
                                              перемикає open, інакше меню блимало б. --}}
                                         @click.outside="if (! $event.target.closest('[data-slot-trigger]')) open = false"
                                         :style="`position:fixed; left:${mx}px; top:${my}px; transform:translateX(-50%);
                                                  z-index:2147483000; min-width:186px; padding:6px; border-radius:12px;
                                                  background:#18181b; border:1px solid #52525b;
                                                  box-shadow:0 16px 44px rgba(0,0,0,.85); text-align:left;`">
                                        @foreach([
                                            ['morning', '☀ Ранок',           '1 виїзд'],
                                            ['evening', '☾ Вечір',           '1 виїзд'],
                                            ['full',    '☀☾ Ранок + вечір',  '2 виїзди'],
                                        ] as [$key, $label, $hint])
                                        <button type="button" class="emp-slot-item"
                                                wire:click="setSlot({{ $row['id'] }}, '{{ $date }}', '{{ $key }}')"
                                                @click="open = false"
                                                style="display:flex;width:100%;align-items:center;justify-content:space-between;
                                                       gap:12px;padding:8px 10px;border-radius:8px;cursor:pointer;
                                                       background:{{ $slot === $key ? '#1e3a5f' : 'transparent' }};
                                                       border:none;color:#e4e4e7;font-size:13px;white-space:nowrap;">
                                            <span>{{ $label }}</span>
                                            <span style="color:#71717a;font-size:11px;">{{ $hint }}</span>
                                        </button>
                                        @endforeach

                                        @if($status === 'present')
                                        <button type="button" class="emp-slot-item"
                                                wire:click="setSlot({{ $row['id'] }}, '{{ $date }}', null)"
                                                @click="open = false"
                                                style="display:block;width:100%;text-align:left;margin-top:4px;
                                                       padding:8px 10px;border-radius:8px;cursor:pointer;
                                                       background:transparent;border:none;color:#f87171;font-size:13px;
                                                       border-top:1px solid #27272a;">
                                            Прибрати зміну
                                        </button>
                                        @endif

                                        @if($date > $today)
                                        <p style="margin:5px 9px 3px;color:#52525b;font-size:10px;line-height:1.4;">
                                            Це план. Гроші нарахуються, коли день підтвердять.
                                        </p>
                                        @endif
                                    </div>
                                    </template>
                                </div>
                            @elseif($status === 'present')
                                @php $fill = $isDuty ? '#78350f' : ($row['is_kitchen'] ? '#14532d' : '#1e3a5f'); @endphp
                                <button wire:click="toggleShift({{ $row['id'] }}, '{{ $date }}')" class="emp-cell-btn"
                                    x-tooltip="{ content: @js($isHalf ? $tipHalf : $tipFull), theme: $store.theme }"
                                    style="width:34px;height:34px;border-radius:50%;
                                           background:{{ $isPlan ? 'transparent' : ($isHalf ? 'linear-gradient(90deg, ' . $fill . ' 50%, #27272a 50%)' : $fill) }};
                                           {{ $isPlan ? 'border:2px dashed #60a5fa;' : ($isHalf ? 'border:2px solid ' . $fill . ';' : '') }}
                                           {{ $isDuty ? 'box-shadow:0 0 0 2px #f59e0b;' : '' }}
                                           display:inline-flex;align-items:center;justify-content:center;">
                                    @if($isHalf)
                                        <span style="font-size:13px;font-weight:800;color:#e4e4e7;line-height:1;">½</span>
                                    @else
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                                             stroke="{{ $isDuty ? '#fbbf24' : ($row['is_kitchen'] ? '#22c55e' : '#60a5fa') }}" stroke-width="2.8">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                                        </svg>
                                    @endif
                                </button>
                            @elseif($status === 'absent_today')
                                <button wire:click="toggleShift({{ $row['id'] }}, '{{ $date }}')" class="emp-cell-btn"
                                    x-tooltip="{ content: 'Планував вийти, але вихід не підтверджено — грошей не нараховано. Клік — поставити зміну вручну (нарахується ставка).', theme: $store.theme }"
                                    style="width:34px;height:34px;border-radius:50%;
                                           border:2px dashed #f87171;
                                           background:transparent;
                                           display:inline-flex;align-items:center;justify-content:center;
                                           color:#f87171;font-weight:700;font-size:14px;">
                                    !
                                </button>
                            @elseif($status === 'off')
                                <button wire:click="toggleShift({{ $row['id'] }}, '{{ $date }}')" class="emp-cell-btn"
                                    x-tooltip="{ content: 'Вихідний. Клік — поставити повну зміну на цей день.', theme: $store.theme }"
                                    style="width:34px;height:34px;border-radius:50%;
                                           background:transparent;border:none;
                                           display:inline-flex;align-items:center;justify-content:center;
                                           color:#3f3f46;font-size:17px;font-weight:300;line-height:1;">
                                    –
                                </button>
                            @else
                                {{-- Майбутній день: клік ставить ПЛАН — рядок є, баланс не рухається. --}}
                                <button wire:click="toggleShift({{ $row['id'] }}, '{{ $date }}')" class="emp-cell-btn"
                                    x-tooltip="{ content: 'Запланувати вихід на майбутнє. Гроші НЕ нараховуються, поки менеджер не підтвердить день галочкою над колонкою.', theme: $store.theme }"
                                    style="width:34px;height:34px;border-radius:50%;
                                           background:transparent;border:none;
                                           display:inline-flex;align-items:center;justify-content:center;
                                           color:#27272a;font-size:17px;font-weight:300;line-height:1;">
                                    –
                                </button>
                            @endif

                            {{-- Прапорець пробігу — для кур'єрів (минулі і сьогоднішній день) --}}
                            @php $mil = $dayInfo['mileage'] ?? null; @endphp
                            @if($row['position'] === 'courier' && $mil !== null)
                                @if($mil === 'ok')
                                    <span title="Пробіг внесено"
                                          style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;
                                                 background:#052e16;border:1.5px solid #34d399;
                                                 display:inline-flex;align-items:center;justify-content:center;line-height:1;z-index:2;">
                                        <span style="font-size:10px;color:#34d399;font-weight:800;">✓</span>
                                    </span>
                                @else
                                    <span title="Пробіг не внесено"
                                          style="position:absolute;top:-6px;right:-6px;width:18px;height:18px;border-radius:50%;
                                                 background:#3b2800;border:1.5px solid #fbbf24;
                                                 display:inline-flex;align-items:center;justify-content:center;line-height:1;z-index:2;">
                                        <span style="font-size:11px;color:#fbbf24;font-weight:800;">!</span>
                                    </span>
                                @endif
                            @endif

                            {{-- Зірочка чергового — показуємо завжди для кухаря --}}
                            @if($row['is_cook'])
                                <button wire:click="toggleDuty({{ $row['id'] }}, '{{ $date }}')"
                                    x-tooltip="{ content: @js($isDuty ? 'Зняти чергування — бонус чергового зніметься.' : 'Призначити черговим на цей день: кухар отримає бонус +' . number_format($data['duty_bonus'], 0, '.', ' ') . ' ₴ до ставки.'), theme: $store.theme }"
                                    class="emp-duty-star{{ $isDuty ? ' is-active' : '' }}"
                                    style="position:absolute;top:-8px;right:-8px;width:22px;height:22px;
                                           border-radius:50%;cursor:pointer;padding:0;
                                           background:{{ $isDuty ? '#f59e0b' : '#1f1f22' }};
                                           border:1.5px solid {{ $isDuty ? '#fbbf24' : '#3f3f46' }};
                                           {{ $isDuty ? 'box-shadow:0 0 8px rgba(245, 158, 11, .6);' : '' }}
                                           display:inline-flex;align-items:center;justify-content:center;
                                           transition:transform .12s, background .15s;line-height:1;
                                           z-index:2;">
                                    <span style="font-size:13px;color:{{ $isDuty ? '#000' : '#a1a1aa' }};font-weight:700;line-height:1;">★</span>
                                </button>
                            @endif
                        </div>
                    </td>
                    @endforeach
                </tr>
                @empty
                <tr>
                    <td colspan="{{ 1 + count($dates) }}" style="padding:48px;text-align:center;color:#52525b;font-size:14px;">
                        Співробітників не знайдено
                    </td>
                </tr>
                @endforelse

                {{-- Порції на зміну --}}
                <tr style="background:#111113;border-top:1.5px solid #27272a;">
                    <td class="emp-sticky" style="padding:12px 20px;color:#52525b;font-size:12px;font-weight:500;
                                background:#111113;border-right:1px solid #27272a;white-space:nowrap;">
                        Порцій на зміну
                    </td>
                    @foreach($dates as $date)
                    @php $count = $data['portions'][$date] ?? null; @endphp
                    <td style="text-align:center;padding:12px 4px;">
                        @if($count !== null)
                            <span style="font-size:14px;font-weight:{{ $date === $today ? '700' : '600' }};
                                         color:{{ $date === $today ? '#60a5fa' : '#a1a1aa' }};">
                                {{ $count }}
                            </span>
                        @else
                            <span style="color:#27272a;font-size:14px;">–</span>
                        @endif
                    </td>
                    @endforeach
                </tr>

                {{-- Собівартість праці на 1 порцію (в рамках поточного фільтра ролей) --}}
                <tr style="background:#111113;border-top:1px solid #27272a;">
                    <td class="emp-sticky" style="padding:12px 20px;color:#52525b;font-size:12px;font-weight:500;
                                background:#111113;border-right:1px solid #27272a;white-space:nowrap;">
                        ₴ / порція
                        <span style="color:#3f3f46;font-weight:400;">
                            ({{ $roleFilter === 'all' ? 'всі' : ($roleFilter === 'cook' ? 'кухня' : ($roleFilter === 'courier' ? "кур'єри" : 'менеджери')) }})
                        </span>
                    </td>
                    @foreach($dates as $date)
                    @php
                        $cpp = $data['cost_per_portion'][$date] ?? null;
                        // Градація:
                        //   80–90  — ідеально (бірюзовий)
                        //   < 120  — норма (зелений)
                        //   ≥ 120  — критично (червоний)
                        $cppColor = '#27272a';
                        if ($cpp !== null) {
                            if ($cpp >= 80 && $cpp <= 90)  $cppColor = '#14b8a6';
                            elseif ($cpp < 120)            $cppColor = '#22c55e';
                            else                           $cppColor = '#ef4444';
                        }
                    @endphp
                    <td style="text-align:center;padding:12px 4px;">
                        @if($cpp !== null)
                            <span style="font-size:14px;font-weight:{{ $date === $today ? '700' : '600' }};
                                         color:{{ $cppColor }};">
                                {{ number_format($cpp, 2, '.', ' ') }} ₴
                            </span>
                        @else
                            <span style="color:#27272a;font-size:14px;">–</span>
                        @endif
                    </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    {{-- ЛЕГЕНДА --}}
    <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="width:20px;height:20px;border-radius:50%;background:#14532d;
                        display:flex;align-items:center;justify-content:center;">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </div>
            <span style="color:#71717a;font-size:12px;">Кухня — вийшов</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="width:20px;height:20px;border-radius:50%;background:#1e3a5f;
                        display:flex;align-items:center;justify-content:center;">
                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="3">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                </svg>
            </div>
            <span style="color:#71717a;font-size:12px;">Доставка / офіс — вийшов <span style="color:#52525b;">(курʼєр: 2 виїзди)</span></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="width:20px;height:20px;border-radius:50%;background:linear-gradient(90deg,#1e3a5f 50%,#27272a 50%);border:2px solid #1e3a5f;
                        display:flex;align-items:center;justify-content:center;">
                <span style="font-size:11px;font-weight:800;color:#e4e4e7;line-height:1;">½</span>
            </div>
            <span style="color:#71717a;font-size:12px;">Пів зміни <span style="color:#52525b;">(курʼєр: 1 виїзд, ½ ставки)</span></span>
        </div>
        <div style="display:flex;align-items:center;gap:7px;">
            <span style="width:20px;height:20px;border-radius:50%;border:2px dashed #60a5fa;
                         display:inline-flex;align-items:center;justify-content:center;
                         color:#60a5fa;font-size:11px;font-weight:600;line-height:1;">☀</span>
            <span style="color:#71717a;font-size:12px;">Заплановано <span style="color:#52525b;">(грошей ще нема — підтвердьте день кнопкою ✓ у шапці)</span></span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="width:20px;height:20px;border-radius:50%;border:2px dashed #f87171;
                        display:flex;align-items:center;justify-content:center;">
                <span style="color:#f87171;font-size:10px;font-weight:700;line-height:1;">!</span>
            </div>
            <span style="color:#71717a;font-size:12px;">Не вийшов</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <span style="color:#3f3f46;font-size:17px;font-weight:300;line-height:1;">–</span>
            <span style="color:#71717a;font-size:12px;">Вихідний / кліпнути щоб додати зміну</span>
        </div>
        <div style="display:flex;align-items:center;gap:6px;">
            <div style="position:relative;width:20px;height:20px;">
                <div style="width:20px;height:20px;border-radius:50%;background:#78350f;box-shadow:0 0 0 2px #f59e0b;
                            display:flex;align-items:center;justify-content:center;">
                    <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="3">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
                    </svg>
                </div>
                <span style="position:absolute;top:-4px;right:-4px;width:11px;height:11px;border-radius:50%;
                             background:#f59e0b;color:#000;font-size:7px;font-weight:700;
                             display:flex;align-items:center;justify-content:center;line-height:1;">★</span>
            </div>
            <span style="color:#71717a;font-size:12px;">Черговий кухар (+{{ number_format($data['duty_bonus'] ?? 0, 0, '.', ' ') }} ₴)</span>
        </div>
    </div>

    {{-- ЛЕГЕНДА: собівартість праці на 1 порцію --}}
    <div style="margin-top:18px;padding:14px 18px;background:#18181b;border:1px solid #27272a;border-radius:12px;">
        <div style="color:#52525b;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">
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
        <div style="color:#52525b;font-size:11px;margin-top:10px;line-height:1.5;">
            Рахується для поточної вкладки: сума ставок усіх співробітників, які вийшли в цей день,
            ділиться на кількість порцій. Що нижче — то ефективніше виробництво.
        </div>
    </div>

</div>
</x-filament-panels::page>
