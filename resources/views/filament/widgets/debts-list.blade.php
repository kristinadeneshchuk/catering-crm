<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Клієнти з боргом</x-slot>
        <x-slot name="headerEnd">
            <div style="display:flex; align-items:center; gap:1rem;">
                <span style="font-size:0.75rem; color:#9ca3af;">{{ $debtsList->count() }} клієнтів</span>
                <span style="font-size:0.9rem; font-weight:700; color:#f87171;">
                    {{ number_format($debtsList->sum('due'), 0, '.', ' ') }} ₴
                </span>
            </div>
        </x-slot>

        @if($debtsList->isEmpty())
            <div style="padding:2rem; text-align:center; color:#4ade80; font-size:0.875rem;">
                ✓ Боржників немає
            </div>
        @else
            {{-- Статистика зверху: активні (на підписці) vs завершені, але винні --}}
            @php
                $activeDebtors   = $debtsList->whereIn('status', ['active', 'new']);
                $finishedDebtors = $debtsList->where('status', 'finished');
                $totalActive = $activeDebtors->count();   $sumActive   = $activeDebtors->sum('due');
                $totalFin    = $finishedDebtors->count(); $sumFin      = $finishedDebtors->sum('due');
            @endphp
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; margin-bottom:1rem;">
                <div style="background:rgba(16,185,129,0.05); border:1px solid rgba(16,185,129,0.2); border-radius:0.5rem; padding:0.75rem 1rem;">
                    <p style="font-size:0.7rem; color:#9ca3af; margin:0 0 0.25rem; text-transform:uppercase; letter-spacing:0.05em;">Активні (на підписці)</p>
                    <p style="font-size:1.1rem; font-weight:700; color:#fb923c; margin:0;">{{ number_format($sumActive, 0, '.', ' ') }} ₴
                        <span style="font-size:0.75rem; color:#9ca3af; font-weight:400;">({{ $totalActive }} кл.)</span>
                    </p>
                </div>
                <div style="background:rgba(248,113,113,0.06); border:1px solid rgba(248,113,113,0.25); border-radius:0.5rem; padding:0.75rem 1rem;">
                    <p style="font-size:0.7rem; color:#9ca3af; margin:0 0 0.25rem; text-transform:uppercase; letter-spacing:0.05em;">Завершені — стягнути</p>
                    <p style="font-size:1.1rem; font-weight:700; color:#f87171; margin:0;">{{ number_format($sumFin, 0, '.', ' ') }} ₴
                        <span style="font-size:0.75rem; color:#9ca3af; font-weight:400;">({{ $totalFin }} кл.)</span>
                    </p>
                </div>
            </div>

            {{-- Скролюємий список --}}
            <div style="max-height:420px; overflow-y:auto; margin:0 -1.5rem; padding:0 1.5rem;">
                <table style="width:100%; border-collapse:collapse;">
                    @php
                        $thBase = 'padding:0.5rem 0.75rem;font-size:0.7rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;cursor:pointer;user-select:none;white-space:nowrap;';
                        $thActive = 'color:#60a5fa;';
                        $thGray   = 'color:#6b7280;';
                        $arrow = fn($col) => $sortBy === $col
                            ? ($sortDir === 'asc'
                                ? '<svg style="display:inline;width:10px;height:10px;margin-left:3px;vertical-align:middle;" viewBox="0 0 10 10" fill="currentColor"><path d="M5 2l4 6H1z"/></svg>'
                                : '<svg style="display:inline;width:10px;height:10px;margin-left:3px;vertical-align:middle;" viewBox="0 0 10 10" fill="currentColor"><path d="M5 8L1 2h8z"/></svg>')
                            : '<svg style="display:inline;width:10px;height:10px;margin-left:3px;vertical-align:middle;opacity:0.3;" viewBox="0 0 10 14" fill="currentColor"><path d="M5 0l3.5 5h-7z"/><path d="M5 14L1.5 9h7z"/></svg>';
                    @endphp
                    <thead style="position:sticky; top:0; z-index:1; background:#1f2937;">
                        <tr style="border-bottom:1px solid #374151;">
                            <th style="{{ $thBase }}{{ $thGray }}text-align:left;">№</th>
                            <th wire:click="sortByColumn('client_id')"
                                style="{{ $thBase }}{{ $sortBy === 'client_id' ? $thActive : $thGray }}text-align:left;">
                                Клієнт{!! $arrow('client_id') !!}
                            </th>
                            <th wire:click="sortByColumn('start_date')"
                                style="{{ $thBase }}{{ $sortBy === 'start_date' ? $thActive : $thGray }}text-align:left;">
                                Дата{!! $arrow('start_date') !!}
                            </th>
                            <th wire:click="sortByColumn('status')"
                                style="{{ $thBase }}{{ $sortBy === 'status' ? $thActive : $thGray }}text-align:center;">
                                Статус{!! $arrow('status') !!}
                            </th>
                            <th wire:click="sortByColumn('final_price')"
                                style="{{ $thBase }}{{ $sortBy === 'final_price' ? $thActive : $thGray }}text-align:right;">
                                Борг{!! $arrow('final_price') !!}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($debtsList as $row)
                        <tr style="border-bottom:1px solid rgba(55,65,81,0.5);"
                            onmouseover="this.style.background='rgba(55,65,81,0.3)'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:0.5rem 0.75rem; font-size:0.75rem; color:#6b7280; white-space:nowrap;">
                                {{ $row['order_id'] ? '#'.$row['order_id'] : '—' }}
                            </td>
                            <td style="padding:0.5rem 0.75rem; font-size:0.8rem; font-weight:500;">
                                @if($row['client_url'])
                                    <a href="{{ $row['client_url'] }}" style="color:#60a5fa; text-decoration:none;"
                                       onmouseover="this.style.textDecoration='underline'"
                                       onmouseout="this.style.textDecoration='none'">
                                        {{ $row['client_name'] }}
                                    </a>
                                @else
                                    <span style="color:#e5e7eb;">{{ $row['client_name'] }}</span>
                                @endif
                            </td>
                            <td style="padding:0.5rem 0.75rem; font-size:0.75rem; color:#9ca3af; white-space:nowrap;">
                                {{ $row['start_date'] }}–{{ $row['end_date'] }}
                                <span style="font-size:0.65rem; color:#6b7280; margin-left:0.25rem;">({{ $row['duration'] }} дн.)</span>
                            </td>
                            <td style="padding:0.5rem 0.75rem; text-align:center;">
                                @if($row['status'] === 'active')
                                    <span style="display:inline-flex; align-items:center; background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); color:#34d399; font-size:0.65rem; font-weight:600; padding:0.125rem 0.5rem; border-radius:9999px; white-space:nowrap;">
                                        Активний
                                    </span>
                                @elseif($row['status'] === 'new')
                                    <span style="display:inline-flex; align-items:center; background:rgba(55,65,81,0.5); border:1px solid #374151; color:#9ca3af; font-size:0.65rem; font-weight:600; padding:0.125rem 0.5rem; border-radius:9999px; white-space:nowrap;">
                                        Новий
                                    </span>
                                @else
                                    <span style="display:inline-flex; align-items:center; background:rgba(248,113,113,0.12); border:1px solid rgba(248,113,113,0.35); color:#f87171; font-size:0.65rem; font-weight:600; padding:0.125rem 0.5rem; border-radius:9999px; white-space:nowrap;">
                                        Завершено
                                    </span>
                                @endif
                            </td>
                            <td style="padding:0.5rem 0.75rem; text-align:right; white-space:nowrap;">
                                <span style="font-size:0.85rem; font-weight:700; color:#f87171;">
                                    {{ number_format($row['due'], 0, '.', ' ') }} ₴
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- підсумок --}}
            <div style="display:flex; align-items:center; justify-content:space-between; padding:0.75rem 0 0; margin-top:0.75rem; border-top:2px solid #374151;">
                <span style="font-size:0.8rem; color:#9ca3af; font-weight:500;">Загальний борг</span>
                <span style="font-size:1.2rem; font-weight:800; color:#f87171;">
                    {{ number_format($debtsList->sum('due'), 0, '.', ' ') }} ₴
                </span>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
