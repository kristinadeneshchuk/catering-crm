<x-filament-panels::page>
<style>
    /* Телефон: колонка «#» — довідкова нумерація, ховаємо, а падінги тиснемо,
       щоб «Купити» (головна цифра для закупівлі) не обрізалась. */
    @media (max-width: 767.98px) {
        .shop-table th:first-child, .shop-table td:first-child { display: none; }
        .shop-table th, .shop-table td { padding-left: 6px !important; padding-right: 6px !important; }
        .shop-table { font-size: 12px; }
    }
</style>

    <x-filament::section>
        <form wire:submit.prevent="calculate">
            {{ $this->form }}
        </form>
    </x-filament::section>

    @if(!empty($this->missingPlans))
        <div style="background:#7f1d1d; border:2px solid #f87171; border-radius:12px; padding:14px 18px; margin-bottom:18px; color:#fee2e2;">
            <div style="font-weight:900; font-size:14px; margin-bottom:8px; text-transform:uppercase; letter-spacing:0.3px;">
                ⚠️ Не вистачає меню для деяких планів
            </div>
            @foreach($this->missingPlans as $mp)
                <div style="margin-bottom:6px; font-size:13px;">
                    <strong style="color:#fff;">{{ $mp['plan']->name }}</strong> — день №{{ $mp['day_number'] }} циклу не створено.
                    Зачеплено клієнтів: <strong>{{ $mp['orders_count'] }}</strong>
                    @if(!empty($mp['client_names']))
                        ({{ implode(', ', $mp['client_names']) }}@if($mp['orders_count'] > count($mp['client_names'])), …@endif)
                    @endif
                </div>
            @endforeach
            <div style="font-size:11px; margin-top:8px; color:#fecaca;">
                Створи день меню у «Циклічне меню» → відповідна вкладка плану → «Додати день».
            </div>
        </div>
    @endif

    @if(empty($shoppingList))
        <div style="padding:32px;text-align:center;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:12px;">
            <div style="font-size:32px;color:#22c55e;">✓</div>
            <div style="font-weight:700;font-size:16px;margin-top:8px;">Усе є на складі!</div>
            <div style="color:#6b7280;margin-top:4px;">На обрану дату купувати нічого не потрібно.</div>
        </div>
    @else
        @php
            $toBuyList  = array_filter($shoppingList, fn($r) => !$r['enough']);
            $enoughList = array_filter($shoppingList, fn($r) => $r['enough']);
            $fmt = fn($val, $unit) => number_format($val, $val < 10 ? 2 : 1, '.', ' ') . ' ' . $unit;
        @endphp

        {{-- Шапка з кнопкою --}}
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <div>
                <span style="font-size:18px;font-weight:800;">Список покупок</span>
                <span style="margin-left:10px;font-size:13px;color:#6b7280;">
                    Потрібно купити: <strong style="color:#ef4444;">{{ count($toBuyList) }}</strong> позицій
                    · Достатньо: <strong style="color:#22c55e;">{{ count($enoughList) }}</strong>
                </span>
            </div>
            <a href="{{ $this->getPrintUrl() }}" target="_blank"
               style="display:inline-flex;align-items:center;gap:6px;background:#1e293b;color:white;padding:8px 18px;border-radius:8px;font-weight:700;font-size:13px;text-decoration:none;">
                Друкувати
            </a>
        </div>

        {{-- ========== ТРЕБА КУПИТИ ========== --}}
        @if(!empty($toBuyList))
        <x-filament::section>
            <x-slot name="heading">Треба купити · {{ count($toBuyList) }} позицій</x-slot>
            <table class="shop-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid rgba(255,255,255,0.1);">
                        <th style="text-align:left;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">#</th>
                        <th style="text-align:left;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">Продукт</th>
                        <th style="text-align:right;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">Потреба</th>
                        <th style="text-align:right;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">На складі</th>
                        <th style="text-align:right;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">Купити</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($toBuyList as $i => $row)
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                        <td style="padding:7px 10px;font-size:11px;color:#6b7280;">{{ $i + 1 }}</td>
                        <td style="padding:7px 10px;font-weight:600;font-size:13px;">{{ $row['name'] }}</td>
                        <td style="padding:7px 10px;text-align:right;font-size:12px;color:#9ca3af;">{{ $fmt($row['need'], $row['unit']) }}</td>
                        <td style="padding:7px 10px;text-align:right;font-size:12px;color:{{ $row['stock'] < 0 ? '#f87171' : '#6b7280' }};font-weight:{{ $row['stock'] < 0 ? '700' : '400' }};">
                            {{ $fmt($row['stock'], $row['unit']) }}
                        </td>
                        <td style="padding:7px 10px;text-align:right;font-size:15px;font-weight:900;color:{{ $row['stock'] < 0 ? '#f87171' : '#fbbf24' }};">
                            {{ $fmt($row['to_buy'], $row['unit']) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
        @endif

        {{-- ========== ДОСТАТНЬО ========== --}}
        @if(!empty($enoughList))
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Достатньо на складі · {{ count($enoughList) }} позицій</x-slot>
            <table class="shop-table" style="width:100%;border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:2px solid rgba(255,255,255,0.1);">
                        <th style="text-align:left;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">#</th>
                        <th style="text-align:left;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">Продукт</th>
                        <th style="text-align:right;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">Потреба</th>
                        <th style="text-align:right;padding:6px 10px;font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;">На складі</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($enoughList as $i => $row)
                    <tr style="border-bottom:1px solid rgba(255,255,255,0.06);">
                        <td style="padding:7px 10px;font-size:11px;color:#6b7280;">{{ $i + 1 }}</td>
                        <td style="padding:7px 10px;font-weight:600;font-size:13px;">{{ $row['name'] }}</td>
                        <td style="padding:7px 10px;text-align:right;font-size:12px;color:#9ca3af;">{{ $fmt($row['need'], $row['unit']) }}</td>
                        <td style="padding:7px 10px;text-align:right;font-size:12px;color:#4ade80;font-weight:700;">{{ $fmt($row['stock'], $row['unit']) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
