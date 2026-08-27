<x-filament-panels::page>

@php
    $stats          = $this->overallStats;
    $rewards        = $this->pendingRewards;
    $dishes         = $this->dishStats;
    $ratings        = $this->ratings;
    $rewardsEnabled = (bool)(int) \App\Models\Setting::where('key', 'rewards_enabled')->value('value');

    $statCards = [
        ['label' => 'Всього відгуків', 'value' => $stats['total'],    'unit' => 'відг.', 'icon' => 'heroicon-o-chat-bubble-left-ellipsis', 'accent' => '#38bdf8', 'desc' => 'За весь час'],
        ['label' => 'Середня оцінка',  'value' => $stats['avg'] ?: '—', 'unit' => '★',   'icon' => 'heroicon-o-star',                     'accent' => '#fbbf24', 'desc' => 'По всіх стравах'],
        ...($rewardsEnabled ? [
            ['label' => 'Видати нагород', 'value' => $stats['pending'], 'unit' => 'клієнт', 'icon' => 'heroicon-o-gift',        'accent' => $stats['pending'] > 0 ? '#a78bfa' : '#6b7280', 'desc' => $stats['pending'] > 0 ? 'Потребують видачі' : 'Всі видані'],
            ['label' => 'Видано нагород', 'value' => $stats['given'],   'unit' => 'разів',  'icon' => 'heroicon-o-check-badge', 'accent' => '#4ade80', 'desc' => 'Успішно вручено'],
        ] : []),
    ];

    $getStarColor = function(float $avg): string {
        if ($avg >= 4.5) return '#4ade80';
        if ($avg >= 3.5) return '#a3e635';
        if ($avg >= 2.5) return '#fbbf24';
        if ($avg >= 1.5) return '#fb923c';
        return '#f87171';
    };
@endphp

<div style="display:flex;flex-direction:column;gap:1.25rem;">

    {{-- ── СТАТИСТИКА (такий самий стиль як інфопанель) ── --}}
    <div style="display:grid;grid-template-columns:repeat({{ $rewardsEnabled ? 4 : 2 }},1fr);gap:1rem;">
        @foreach($statCards as $card)
        @php $accent = $card['accent']; @endphp
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;padding:1.25rem 1.375rem;display:flex;flex-direction:column;gap:0.75rem;position:relative;overflow:hidden;">
            <div style="position:absolute;top:0;left:0;right:0;height:2px;background:{{ $accent }};border-radius:0.875rem 0.875rem 0 0;"></div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.7rem;font-weight:600;color:#9ca3af;text-transform:uppercase;letter-spacing:0.06em;">{{ $card['label'] }}</span>
                <div style="width:2rem;height:2rem;border-radius:0.5rem;background:{{ $accent }}1a;display:flex;align-items:center;justify-content:center;">
                    <x-dynamic-component :component="$card['icon']" style="width:1rem;height:1rem;color:{{ $accent }};" />
                </div>
            </div>
            <div style="display:flex;align-items:baseline;gap:0.375rem;">
                <span style="font-size:2rem;font-weight:800;color:#f1f5f9;line-height:1;">{{ $card['value'] }}</span>
                <span style="font-size:0.8rem;color:#6b7280;font-weight:500;">{{ $card['unit'] }}</span>
            </div>
            <span style="font-size:0.72rem;color:#6b7280;">{{ $card['desc'] }}</span>
        </div>
        @endforeach
    </div>

    {{-- ── НАГОРОДИ ── --}}
    @if($rewardsEnabled && $rewards->isNotEmpty())
    <div style="background:rgba(167,139,250,0.06);border:1px solid rgba(167,139,250,0.25);border-radius:0.875rem;overflow:hidden;position:relative;">
        <div style="position:absolute;top:0;left:0;right:0;height:2px;background:#a78bfa;border-radius:0.875rem 0.875rem 0 0;"></div>
        <div style="padding:0.875rem 1.25rem;display:flex;align-items:center;gap:0.5rem;border-bottom:1px solid rgba(167,139,250,0.15);">
            <x-heroicon-o-gift style="width:1rem;height:1rem;color:#a78bfa;" />
            <span style="font-size:0.8rem;font-weight:700;color:#c4b5fd;">Нагороди до видачі</span>
            <span style="margin-left:0.25rem;background:#a78bfa;color:white;font-size:0.7rem;font-weight:700;padding:0.1rem 0.5rem;border-radius:100px;">{{ $rewards->count() }}</span>
        </div>
        @foreach($rewards as $ro)
        @php
            $goal = $ro->duration > 5 ? 5 : $ro->duration;
            $clientEditUrl = route('filament.admin.resources.clients.edit', $ro->client_id);
            $orderEditUrl  = route('filament.admin.resources.orders.edit', $ro->id);
        @endphp
        <div style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(167,139,250,0.08);">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;">
                {{-- Ліва частина: інфо про клієнта і замовлення --}}
                <div style="display:flex;gap:1rem;min-width:0;flex:1;">
                    {{-- Іконка нагороди --}}
                    <div style="width:2.25rem;height:2.25rem;border-radius:0.625rem;background:rgba(167,139,250,0.15);border:1px solid rgba(167,139,250,0.3);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                        <span style="font-size:1rem;">🎁</span>
                    </div>
                    {{-- Деталі --}}
                    <div style="min-width:0;flex:1;">
                        {{-- Рядок 1: ім'я клієнта + телефон --}}
                        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                            <a href="{{ $clientEditUrl }}" style="font-size:0.875rem;font-weight:700;color:#c4b5fd;text-decoration:none;" onmouseover="this.style.color='#ddd6fe'" onmouseout="this.style.color='#c4b5fd'">
                                {{ $ro->client->name }}
                            </a>
                            @if($ro->client->phone)
                            <a href="tel:{{ $ro->client->phone }}" style="font-size:0.75rem;color:#a78bfa;text-decoration:none;display:flex;align-items:center;gap:0.25rem;">
                                <span style="font-size:0.7rem;">📞</span> {{ $ro->client->phone }}
                            </a>
                            @endif
                        </div>
                        {{-- Рядок 2: замовлення + тариф --}}
                        <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                            <a href="{{ $orderEditUrl }}" style="font-size:0.72rem;color:#818cf8;text-decoration:none;display:flex;align-items:center;gap:0.25rem;" onmouseover="this.style.color='#a5b4fc'" onmouseout="this.style.color='#818cf8'">
                                <span>↗</span> Замовлення #{{ $ro->id }}
                            </a>
                            @if($ro->tariff)
                            <span style="font-size:0.7rem;color:#6b7280;">·</span>
                            <span style="font-size:0.72rem;color:#9ca3af;">{{ $ro->tariff->name }}</span>
                            @endif
                            @if($ro->calories)
                            <span style="font-size:0.7rem;color:#6b7280;">·</span>
                            <span style="font-size:0.72rem;color:#9ca3af;">{{ $ro->calories }} ккал</span>
                            @endif
                        </div>
                        {{-- Рядок 3: прогрес + дата --}}
                        <div style="display:flex;align-items:center;gap:0.75rem;margin-top:0.375rem;flex-wrap:wrap;">
                            <div style="display:flex;align-items:center;gap:0.25rem;">
                                @for($d = 1; $d <= $goal; $d++)
                                <div style="width:1.25rem;height:0.375rem;border-radius:100px;background:#4ade80;"></div>
                                @endfor
                                <span style="font-size:0.7rem;color:#4ade80;font-weight:700;margin-left:0.25rem;">{{ $goal }}/{{ $goal }} днів</span>
                            </div>
                            <span style="font-size:0.7rem;color:#6b7280;">·</span>
                            <span style="font-size:0.7rem;color:#6b7280;">Виконано {{ $ro->updated_at->format('d.m.Y') }}</span>
                        </div>
                    </div>
                </div>
                {{-- Кнопка видачі --}}
                <button wire:click="giveReward({{ $ro->id }})" wire:confirm="Видати нагороду для {{ $ro->client->name }}?"
                    style="padding:0.5rem 1rem;background:#7c3aed;color:white;font-size:0.75rem;font-weight:700;border:none;border-radius:0.5rem;cursor:pointer;flex-shrink:0;white-space:nowrap;">
                    ✓ Видати
                </button>
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- ── ДВА СТОВПЦІ ── --}}
    <div style="display:grid;grid-template-columns:320px 1fr;gap:1rem;align-items:start;">

        {{-- Рейтинг страв --}}
        @if($dishes->isNotEmpty())
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;position:relative;">
            <div style="position:absolute;top:0;left:0;right:0;height:2px;background:#fbbf24;border-radius:0.875rem 0.875rem 0 0;"></div>
            <div style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;">
                <span style="font-size:0.8rem;font-weight:700;color:#f1f5f9;">Рейтинг страв</span>
                <span style="font-size:0.72rem;color:#6b7280;">{{ $dishes->count() }} страв</span>
            </div>
            <div style="max-height:480px;overflow-y:auto;">
                @foreach($dishes as $i => $stat)
                @php
                    $avg = round($stat->avg_stars, 1);
                    $c   = $getStarColor($avg);
                    $pct = min($avg / 5 * 100, 100);
                @endphp
                <div style="padding:0.75rem 1.25rem;display:flex;align-items:center;gap:0.75rem;border-bottom:1px solid rgba(255,255,255,0.04);">
                    <span style="font-size:0.7rem;font-weight:700;color:#374151;width:1rem;text-align:center;flex-shrink:0;">{{ $i+1 }}</span>
                    <div style="flex:1;min-width:0;">
                        <p style="font-size:0.8rem;font-weight:600;color:#e2e8f0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $stat->dish?->name ?? '—' }}</p>
                        <div style="display:flex;align-items:center;gap:0.5rem;margin-top:0.375rem;">
                            <div style="flex:1;height:3px;background:rgba(255,255,255,0.08);border-radius:100px;overflow:hidden;">
                                <div style="height:100%;width:{{ $pct }}%;background:{{ $c }};border-radius:100px;"></div>
                            </div>
                            <span style="font-size:0.65rem;color:#6b7280;flex-shrink:0;">{{ $stat->total }}</span>
                        </div>
                    </div>
                    <span style="font-size:0.875rem;font-weight:800;color:{{ $c }};flex-shrink:0;">{{ $avg }}★</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Всі відгуки --}}
        <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;position:relative;">
            <div style="position:absolute;top:0;left:0;right:0;height:2px;background:#38bdf8;border-radius:0.875rem 0.875rem 0 0;"></div>

            {{-- Шапка --}}
            <div style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.75rem;">
                    <span style="font-size:0.8rem;font-weight:700;color:#f1f5f9;">Всі відгуки</span>
                    @if($ratings->total() > 0)
                    <span style="font-size:0.72rem;color:#6b7280;">{{ $ratings->total() }} записів</span>
                    @endif
                </div>
                <div style="display:flex;gap:0.5rem;">
                    <input wire:model.live.debounce.300ms="filterClient" type="text" placeholder="Клієнт..."
                        style="flex:1;min-width:0;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:0.5rem;padding:0.375rem 0.75rem;font-size:0.75rem;color:#e2e8f0;outline:none;" />
                    <input wire:model.live.debounce.300ms="filterDish" type="text" placeholder="Страва..."
                        style="flex:1;min-width:0;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:0.5rem;padding:0.375rem 0.75rem;font-size:0.75rem;color:#e2e8f0;outline:none;" />
                    <select wire:model.live="filterStars"
                        style="background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);border-radius:0.5rem;padding:0.375rem 0.75rem;font-size:0.75rem;color:#e2e8f0;outline:none;">
                        <option value="" style="background:#1f2937;">Всі ★</option>
                        @for($s=5;$s>=1;$s--)
                        <option value="{{ $s }}" style="background:#1f2937;">{{ str_repeat('★',$s) }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            @if($ratings->isEmpty())
                <div style="padding:4rem 1rem;text-align:center;">
                    <div style="font-size:2.5rem;color:rgba(255,255,255,0.08);margin-bottom:0.75rem;">★</div>
                    <p style="font-size:0.875rem;font-weight:600;color:#4b5563;">Відгуків ще немає</p>
                    <p style="font-size:0.75rem;color:#374151;margin-top:0.25rem;">Клієнти побачать форму оцінки в своєму меню</p>
                </div>
            @else
                <div style="max-height:480px;overflow-y:auto;">
                    @foreach($ratings as $rating)
                    @php $c = $getStarColor($rating->stars); @endphp
                    <div style="padding:0.875rem 1.25rem;border-bottom:1px solid rgba(255,255,255,0.04);display:flex;align-items:flex-start;gap:0.875rem;">
                        {{-- Оцінка --}}
                        <div style="width:2.5rem;height:2.5rem;border-radius:0.625rem;background:{{ $c }}18;border:1px solid {{ $c }}33;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;">
                            <span style="font-size:0.9rem;font-weight:800;color:{{ $c }};line-height:1;">{{ $rating->stars }}</span>
                            <span style="font-size:0.6rem;color:{{ $c }};">★</span>
                        </div>
                        {{-- Контент --}}
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                                <div style="display:flex;align-items:center;gap:0.375rem;min-width:0;flex:1;">
                                    <span style="font-size:0.8rem;font-weight:700;color:#f1f5f9;flex-shrink:0;">{{ $rating->order?->client?->name ?? '—' }}</span>
                                    <span style="color:#374151;flex-shrink:0;">·</span>
                                    <span style="font-size:0.72rem;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $rating->dish?->name ?? '—' }}</span>
                                </div>
                                <span style="font-size:0.7rem;color:#4b5563;flex-shrink:0;">{{ \Carbon\Carbon::parse($rating->date)->format('d.m.Y') }}</span>
                            </div>
                            <div style="display:flex;gap:1px;margin-top:0.375rem;">
                                @for($i=1;$i<=5;$i++)
                                <span style="font-size:0.875rem;color:{{ $i <= $rating->stars ? $c : 'rgba(255,255,255,0.1)' }};">★</span>
                                @endfor
                            </div>
                            @if($rating->comment)
                            <p style="font-size:0.72rem;color:#9ca3af;margin-top:0.5rem;font-style:italic;padding:0.375rem 0.625rem;background:rgba(255,255,255,0.04);border-radius:0.375rem;border-left:2px solid {{ $c }}55;">"{{ $rating->comment }}"</p>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
                @if($ratings->hasPages())
                <div style="padding:0.75rem 1.25rem;border-top:1px solid rgba(255,255,255,0.06);">{{ $ratings->links() }}</div>
                @endif
            @endif
        </div>

    </div>
</div>
</x-filament-panels::page>
