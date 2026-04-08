<x-filament-panels::page>
    <style>
        .pm-nav { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:8px; }
        .pm-btn {
            display:inline-flex; align-items:center; gap:6px;
            padding:8px 18px; border-radius:10px; font-size:13px; font-weight:700;
            cursor:pointer; border:none; transition:all .15s;
        }
        .pm-btn-ghost {
            background:var(--pm-card-bg,#222222); color:#999999;
            border:1.5px solid #2d2d2d;
        }
        .pm-btn-ghost:hover { background:#2d2d2d; color:#e2e8f0; }
        .pm-date-input {
            background:#222222; color:#f1f5f9; border:1.5px solid #2d2d2d;
            border-radius:10px; padding:8px 14px; font-size:14px; font-weight:700;
            outline:none;
        }
        .pm-date-input:focus { border-color:#6b6b6b; outline:none !important; box-shadow:none !important; }
        .pm-meta { margin-left:auto; font-size:13px; color:#6b6b6b; font-weight:600; }

        /* Картка клієнта */
        .pm-card {
            background:#222222; border-radius:16px;
            border:1.5px solid #2d2d2d; overflow:hidden;
            margin-bottom:12px; box-shadow:0 2px 8px rgba(0,0,0,.25);
        }
        .pm-card-head {
            display:flex; align-items:center; justify-content:space-between;
            padding:14px 20px; border-bottom:1px solid #2d2d2d;
            background:#141414;
        }
        .pm-card-head-left { display:flex; align-items:center; gap:12px; }
        .pm-status-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .pm-client-name { font-size:16px; font-weight:800; color:#f1f5f9; text-decoration:none; }
        .pm-client-name:hover { color:#999999; }
        .pm-kcal { font-size:13px; color:#6b6b6b; font-weight:600; }
        .pm-card-head-right { display:flex; align-items:center; gap:8px; }
        .pm-badge { font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
        .pm-badge-morning { background:#451a03; color:#fb923c; }
        .pm-badge-evening { background:#2e1065; color:#a78bfa; }
        .pm-counter { font-size:12px; color:#555555; font-weight:700; }

        /* Рядок прийому їжі */
        .pm-meal-row {
            display:flex; align-items:center;
            border-bottom:1px solid #222222; transition:background .1s;
        }
        .pm-meal-row:last-child { border-bottom:none; }
        .pm-meal-row:hover { background:#1c1c1c; }
        .pm-meal-label {
            width:130px; flex-shrink:0;
            font-size:12px; font-weight:700; color:#6b6b6b;
            text-transform:uppercase; letter-spacing:.5px;
            padding:14px 0 14px 20px;
        }
        .pm-meal-btn-wrap { flex:1; padding:10px 16px 10px 0; }
        .pm-meal-btn {
            width:100%; background:#141414; color:#999999;
            border:1.5px solid #2d2d2d; border-radius:10px;
            padding:9px 14px; font-size:14px; font-weight:600;
            text-align:left; cursor:pointer; transition:all .15s;
            display:flex; align-items:center; justify-content:space-between; gap:8px;
        }
        .pm-meal-btn:hover { border-color:#555555; color:#e2e8f0; }
        .pm-meal-btn.is-filled { border-color:#16a34a; color:#86efac; background:#052e16; }
        .pm-meal-btn.is-filled:hover { border-color:#22c55e; }
        .pm-meal-btn-text { flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .pm-meal-btn-icon { flex-shrink:0; opacity:.4; }
        .pm-meal-check {
            width:36px; flex-shrink:0;
            display:flex; align-items:center; justify-content:center;
            padding-right:16px;
        }

        /* Порожній стан */
        .pm-empty { text-align:center; padding:80px 20px; color:#555555; }
        .pm-empty-icon {
            width:56px; height:56px; border-radius:50%;
            background:#222222; display:flex; align-items:center;
            justify-content:center; margin:0 auto 16px;
        }

        /* ========== МОДАЛЬНЕ ВІКНО ========== */
        .pm-modal-backdrop {
            position:fixed; inset:0; z-index:9999;
            background:rgba(0,0,0,.85); backdrop-filter:blur(6px);
            display:flex; align-items:flex-end; justify-content:center;
        }
        @media (min-width:640px) {
            .pm-modal-backdrop { align-items:center; padding:24px; }
        }
        .pm-modal {
            background:#1a1a1a; width:100%; max-width:700px;
            max-height:90vh; display:flex; flex-direction:column;
            border:1.5px solid #2d2d2d;
            box-shadow:0 -8px 48px rgba(0,0,0,.6);
            border-radius:20px 20px 0 0; overflow:hidden;
        }
        @media (min-width:640px) {
            .pm-modal { border-radius:20px; max-height:82vh; }
        }
        .pm-modal-head {
            padding:20px 20px 0; flex-shrink:0; position:relative;
        }
        .pm-modal-close {
            position:absolute; top:0; right:20px;
            background:#2d2d2d; border:none; color:#999999;
            width:30px; height:30px; border-radius:50%;
            cursor:pointer; font-size:14px; display:flex;
            align-items:center; justify-content:center; transition:all .15s;
        }
        .pm-modal-close:hover { background:#555555; color:#f1f5f9; }
        .pm-modal-title { font-size:11px; font-weight:700; color:#6b6b6b; text-transform:uppercase; letter-spacing:.8px; margin-bottom:3px; }
        .pm-modal-subtitle { font-size:18px; font-weight:800; color:#f1f5f9; margin-bottom:14px; }
        .pm-modal-search {
            width:100%; background:#111111; color:#f1f5f9;
            border:1.5px solid #2d2d2d; border-radius:12px;
            padding:11px 16px; font-size:14px; font-weight:600;
            outline:none !important; box-shadow:none !important;
            margin-bottom:10px; box-sizing:border-box;
        }
        .pm-modal-search:focus {
            border-color:#6b6b6b;
            outline:none !important; box-shadow:none !important;
        }
        .pm-modal-search::placeholder { color:#555555; font-weight:500; }
        .pm-modal-clear {
            display:flex; align-items:center; gap:8px;
            padding:10px 20px; font-size:13px; color:#555555;
            font-weight:600; cursor:pointer; border-top:1px solid #2d2d2d;
            border-bottom:1px solid #141414;
            flex-shrink:0; transition:background .1s;
        }
        .pm-modal-clear:hover { background:#141414; color:#f87171; }
        .pm-dishes-grid {
            flex:1; overflow-y:auto; padding:12px 16px 20px;
            display:grid; grid-template-columns:repeat(2,1fr); gap:8px;
        }
        @media (min-width:480px) { .pm-dishes-grid { grid-template-columns:repeat(3,1fr); } }
        @media (min-width:640px) { .pm-dishes-grid { grid-template-columns:repeat(4,1fr); } }
        .pm-dish-card {
            background:#141414; border:1.5px solid #2a2a2a; border-radius:12px;
            padding:12px 12px; cursor:pointer; transition:all .12s;
        }
        .pm-dish-card:hover { border-color:#4b5563; background:#ffffff0a; }
        .pm-dish-card.is-selected { border-color:#16a34a; background:#052e16; }
        .pm-dish-name { font-size:13px; font-weight:700; color:#e2e8f0; line-height:1.3; margin-bottom:4px; }
        .pm-dish-card.is-selected .pm-dish-name { color:#86efac; }
        .pm-dish-kcal { font-size:11px; font-weight:600; color:#888888; }
        .pm-dish-card.is-selected .pm-dish-kcal { color:#16a34a66; }
        .pm-dish-kitchen-badge {
            display:inline-flex; align-items:center; gap:3px;
            font-size:10px; font-weight:700; color:#16a34a;
            background:#052e16; border:1px solid #166534;
            border-radius:6px; padding:2px 6px; margin-bottom:5px;
        }
        .pm-dish-info-btn {
            display:inline-flex; align-items:center; justify-content:center;
            width:18px; height:18px; border-radius:50%;
            background:#2d2d2d; border:1px solid #555555;
            color:#999999; font-size:11px; font-weight:800;
            cursor:pointer; flex-shrink:0; line-height:1;
            transition:all .15s; vertical-align:middle; margin-left:5px;
        }
        .pm-dish-info-btn:hover { background:#555555; color:#f1f5f9; border-color:#6b6b6b; }
        .pm-dish-ingredients {
            margin-top:8px; padding-top:8px;
            border-top:1px solid #222222;
            font-size:11px; color:#6b6b6b; line-height:1.6;
        }
        .pm-dish-ingredients strong { color:#22c55e; font-weight:700; }
        .pm-modal-section-label {
            grid-column:1/-1; font-size:11px; font-weight:700;
            color:#555555; text-transform:uppercase; letter-spacing:.6px;
            padding:4px 0 2px;
        }
        .pm-no-results { grid-column:1/-1; text-align:center; padding:40px 0; color:#2d2d2d; font-size:14px; font-weight:600; }
    </style>

    <div wire:poll.30s="loadData" x-data="{ search: '' }">

        {{-- Навігація по датах --}}
        <div class="pm-nav">
            <button wire:click="prevDay" class="pm-btn pm-btn-ghost">← Попередній</button>
            <input type="date" wire:model.live="date" class="pm-date-input">
            <button wire:click="nextDay" class="pm-btn pm-btn-ghost">Наступний →</button>
            <input
                x-model="search"
                type="text"
                placeholder="Пошук клієнта..."
                class="pm-date-input"
                style="width:160px;font-weight:600;">
            <span class="pm-meta">
                {{ \Carbon\Carbon::parse($date)->locale('uk')->isoFormat('dddd, D MMMM YYYY') }}
                · {{ count($cards) }}
                {{ count($cards) === 1 ? 'клієнт' : (count($cards) < 5 ? 'клієнти' : 'клієнтів') }}
            </span>
        </div>

        {{-- Порожній стан --}}
        @if(empty($cards))
            <div class="pm-empty">
                <div class="pm-empty-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#555555" stroke-width="2">
                        <circle cx="12" cy="8" r="4"/><path d="M6 20v-2a6 6 0 0 1 12 0v2"/>
                    </svg>
                </div>
                <div style="font-size:15px;font-weight:700;color:#6b6b6b;margin-bottom:6px;">
                    Немає індивідуальних клієнтів на цю дату
                </div>
                <div style="font-size:13px;color:#2d2d2d;">
                    Позначте замовлення як "Персональне" щоб воно з'явилось тут
                </div>
            </div>
        @endif

        {{-- Картки --}}
        @foreach($cards as $card)
            @php
                $filled    = $card['filled'];
                $total     = $card['total'];
                $filledAll = $filled === $total && $total > 0;
                $dotColor  = $filledAll ? '#22c55e' : ($filled === 0 ? '#555555' : '#f59e0b');
                $projectColors = [
                    'success' => ['bg'=>'#052e16','text'=>'#4ade80'],
                    'primary' => ['bg'=>'#222222','text'=>'#999999'],
                    'info'    => ['bg'=>'#082f49','text'=>'#38bdf8'],
                    'warning' => ['bg'=>'#451a03','text'=>'#fb923c'],
                    'danger'  => ['bg'=>'#450a0a','text'=>'#f87171'],
                    'gray'    => ['bg'=>'#222222','text'=>'#999999'],
                ];
                $pc = $projectColors[$card['color']] ?? $projectColors['gray'];
            @endphp

            <div class="pm-card"
                 x-show="!search || '{{ $card['client_id'] }}'.includes(search) || '{{ $card['order_id'] }}'.includes(search) || '{{ addslashes(mb_strtolower($card['client'])) }}'.includes(search.toLowerCase())">
                <div class="pm-card-head">
                    <div class="pm-card-head-left">
                        <div class="pm-status-dot" style="background:{{ $dotColor }};box-shadow:0 0 6px {{ $dotColor }}55;"></div>
                        <a href="{{ url('/admin/clients/' . $card['client_id'] . '/edit') }}"
                           target="_blank" class="pm-client-name">{{ $card['client'] }}</a>
                        <span class="pm-kcal" style="color:#555555;">клієнт #{{ $card['client_id'] }}</span>
                        <span class="pm-kcal" style="color:#555555;">замовлення #{{ $card['order_id'] }}</span>
                        <span class="pm-kcal">{{ $card['calories'] }} ккал</span>
                    </div>
                    <div class="pm-card-head-right">
                        <span class="pm-badge" style="background:{{ $pc['bg'] }};color:{{ $pc['text'] }};">
                            {{ $card['project'] }}
                        </span>
                        <span class="pm-badge {{ $card['is_evening'] ? 'pm-badge-evening' : 'pm-badge-morning' }}">
                            {{ $card['slot'] }}
                        </span>
                        <span class="pm-counter">{{ $filled }}/{{ $total }}</span>
                    </div>
                </div>

                @foreach($card['meals'] as $meal)
                    <div class="pm-meal-row">
                        <div class="pm-meal-label">{{ $meal['meal_type_name'] }}</div>
                        <div class="pm-meal-btn-wrap">
                            <button
                                class="pm-meal-btn {{ $meal['current_dish_id'] ? 'is-filled' : '' }}"
                                wire:click="openDishModal({{ $card['order_id'] }}, {{ $meal['meal_type_id'] }})">
                                <span class="pm-meal-btn-text">
                                    @if($meal['current_dish_id'])
                                        {{ $allDishes[$meal['current_dish_id']] ?? '—' }}
                                    @else
                                        <span style="color:#2d2d2d;">─── не призначено ───</span>
                                    @endif
                                </span>
                                <svg class="pm-meal-btn-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                    <path d="m6 9 6 6 6-6"/>
                                </svg>
                            </button>
                        </div>
                        <div class="pm-meal-check">
                            @if($meal['current_dish_id'])
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5">
                                    <path d="M20 6 9 17l-5-5"/>
                                </svg>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach

    </div>

    {{-- ========== МОДАЛЬНЕ ВІКНО ========== --}}
    @if($modalOrderId !== null)
        <div class="pm-modal-backdrop" wire:click.self="closeDishModal">
            <div
                class="pm-modal"
                x-data="{ search: '', get filtered() { return this.search.trim().toLowerCase(); } }"
                x-init="$nextTick(() => { $refs.search && $refs.search.focus(); })"
                @keydown.escape.window="$wire.closeDishModal()">

                <div class="pm-modal-head">
                    <button class="pm-modal-close" wire:click="closeDishModal" type="button">✕</button>
                    <div class="pm-modal-title">{{ $modalClientName }}</div>
                    <div class="pm-modal-subtitle">{{ $modalMealLabel }}</div>
                    <input
                        x-ref="search"
                        x-model="search"
                        type="text"
                        placeholder="Пошук страви..."
                        class="pm-modal-search">
                </div>

                <div class="pm-modal-clear" wire:click="pickDish(null)" type="button">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M18 6 6 18M6 6l12 12"/>
                    </svg>
                    Прибрати страву
                </div>

                <div class="pm-dishes-grid">
                    @php
                        $kitchenDishes = array_filter($modalDishes, fn($d) => $d['is_kitchen']);
                        $otherDishes   = array_filter($modalDishes, fn($d) => !$d['is_kitchen']);
                        $hasKitchen    = !empty($kitchenDishes);
                    @endphp

                    {{-- Секція "Є на кухні" --}}
                    @if($hasKitchen)
                        <div class="pm-modal-section-label">✓ Є на кухні сьогодні</div>
                        @foreach($kitchenDishes as $dish)
                            <div
                                class="pm-dish-card {{ $modalCurrentDishId == $dish['id'] ? 'is-selected' : '' }}"
                                x-show="!filtered || '{{ addslashes(mb_strtolower($dish['name'])) }}'.includes(filtered)"
                                x-data="{ open: false }"
                                wire:key="dish-{{ $dish['id'] }}">

                                {{-- Бейдж + кнопка інфо --}}
                                <div style="display:flex;align-items:center;margin-bottom:5px;">
                                    <div class="pm-dish-kitchen-badge" style="margin-bottom:0;">
                                        <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg>
                                        {{ $dish['matches'] }} інгр.
                                    </div>
                                    <span class="pm-dish-info-btn"
                                          @click.stop="open = !open"
                                          title="Які інгредієнти збігаються">ⓘ</span>
                                </div>

                                {{-- Назва — клік вибирає страву --}}
                                <div wire:click="pickDish({{ $dish['id'] }})">
                                    <div class="pm-dish-name">{{ $dish['name'] }}</div>
                                </div>

                                {{-- Розкривний список інгредієнтів --}}
                                @if(!empty($dish['matching_ingredients']))
                                    <div class="pm-dish-ingredients" x-show="open" x-collapse>
                                        @foreach($dish['matching_ingredients'] as $ing)
                                            <strong>✓ {{ $ing }}</strong><br>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endif

                    {{-- Секція "Інші страви" --}}
                    @if(!empty($otherDishes))
                        @if($hasKitchen)
                            <div class="pm-modal-section-label">Інші страви</div>
                        @endif
                        @foreach($otherDishes as $dish)
                            <div
                                class="pm-dish-card {{ $modalCurrentDishId == $dish['id'] ? 'is-selected' : '' }}"
                                x-show="!filtered || '{{ addslashes(mb_strtolower($dish['name'])) }}'.includes(filtered)"
                                wire:click="pickDish({{ $dish['id'] }})"
                                wire:key="dish-{{ $dish['id'] }}">
                                <div class="pm-dish-name">{{ $dish['name'] }}</div>
                            </div>
                        @endforeach
                    @endif

                    {{-- Нічого не знайдено --}}
                    <div class="pm-no-results" x-show="filtered && document.querySelectorAll('.pm-dish-card[style*=\'display: none\']').length === {{ count($modalDishes) }}" style="display:none;">
                        Нічого не знайдено
                    </div>
                </div>
            </div>
        </div>
    @endif

</x-filament-panels::page>
