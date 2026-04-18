<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Моє меню — {{ $date->format('d.m.Y') }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #f0f4f8;
            color: #1a202c;
            min-height: 100vh;
        }

        /* ── Header ── */
        .header {
            background: #1a202c;
            color: white;
            padding: 16px 20px 12px;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .header-brand {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            opacity: 0.5;
            margin-bottom: 2px;
        }
        .header-name { font-size: 18px; font-weight: 800; line-height: 1.2; }
        .header-tariff { font-size: 12px; opacity: 0.6; margin-top: 2px; }

        /* ── Progress bar ── */
        .progress-banner {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 16px;
        }
        .progress-banner.reward-unlocked {
            background: linear-gradient(135deg, #f6f0ff 0%, #fff5f0 100%);
            border-color: #d6bcfa;
        }
        .progress-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        .progress-label {
            font-size: 12px;
            font-weight: 700;
            color: #4a5568;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .progress-count {
            font-size: 12px;
            font-weight: 800;
            color: #1a202c;
        }
        .progress-track {
            height: 8px;
            background: #e2e8f0;
            border-radius: 100px;
            overflow: hidden;
            margin-bottom: 8px;
        }
        .progress-fill {
            height: 100%;
            border-radius: 100px;
            background: linear-gradient(90deg, #48bb78, #38a169);
            transition: width 0.4s ease;
        }
        .progress-dots {
            display: flex;
            gap: 6px;
            align-items: center;
        }
        .progress-dot {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: 2px solid #e2e8f0;
            background: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        .progress-dot.done {
            background: #48bb78;
            border-color: #38a169;
        }
        .progress-hint {
            font-size: 11px;
            color: #718096;
            margin-top: 6px;
        }
        .reward-unlocked-msg {
            display: flex;
            align-items: center;
            gap: 10px;
            background: linear-gradient(135deg, #9f7aea, #ed8936);
            color: white;
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 4px;
        }
        .reward-unlocked-msg .reward-icon { font-size: 22px; }
        .reward-unlocked-msg .reward-text { font-size: 13px; font-weight: 700; line-height: 1.4; }
        .reward-given-msg {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f0faf4;
            border: 1px solid #9ae6b4;
            color: #276749;
            border-radius: 10px;
            padding: 10px 14px;
            margin-top: 4px;
            font-size: 13px;
            font-weight: 600;
        }

        /* ── Date navigation ── */
        .date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 12px 16px;
            border-bottom: 1px solid #e2e8f0;
        }
        .date-nav-btn {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 8px 12px;
            border-radius: 8px;
            border: none;
            background: #f0f4f8;
            color: #4a5568;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .date-nav-btn:hover { background: #e2e8f0; }
        .date-nav-btn.disabled { opacity: 0.3; pointer-events: none; }
        .date-center { text-align: center; }
        .date-main { font-size: 16px; font-weight: 800; color: #1a202c; }
        .date-sub { font-size: 11px; color: #718096; margin-top: 1px; }

        /* ── Content ── */
        .content { padding: 16px; max-width: 480px; margin: 0 auto; }

        /* ── Empty state ── */
        .empty-state { text-align: center; padding: 60px 20px; color: #718096; }
        .empty-state-icon { font-size: 48px; margin-bottom: 12px; }
        .empty-state-title { font-size: 18px; font-weight: 700; color: #4a5568; margin-bottom: 8px; }
        .empty-state-text { font-size: 14px; line-height: 1.5; }

        /* ── Dish card ── */
        .meal-section { margin-bottom: 8px; }
        .meal-label {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: #718096;
            padding: 0 4px;
            margin-bottom: 6px;
        }

        .dish-card {
            background: white;
            border-radius: 14px;
            padding: 16px;
            margin-bottom: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .dish-card-link {
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .dish-card-link:active { opacity: 0.85; }

        .dish-card-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 10px;
        }
        .dish-name { font-size: 15px; font-weight: 700; line-height: 1.3; flex: 1; }
        .dish-weight {
            font-size: 14px;
            font-weight: 800;
            color: #2d8a4e;
            white-space: nowrap;
            background: #f0faf4;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .dish-kbju { display: flex; gap: 8px; flex-wrap: wrap; }
        .kbju-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            background: #f7fafc;
            border-radius: 8px;
            padding: 5px 10px;
            min-width: 52px;
        }
        .kbju-value { font-size: 14px; font-weight: 800; color: #2d3748; }
        .kbju-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #a0aec0;
            margin-top: 1px;
        }
        .kbju-item.kcal .kbju-value { color: #e53e3e; }
        .kbju-item.prot .kbju-value { color: #3182ce; }
        .kbju-item.fat  .kbju-value { color: #d69e2e; }
        .kbju-item.carb .kbju-value { color: #38a169; }

        .dish-change-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fff5f5;
            border: 1px solid #fed7d7;
            border-radius: 6px;
            padding: 3px 8px;
            font-size: 10px;
            font-weight: 700;
            color: #c53030;
            margin-top: 8px;
        }
        .dish-arrow { font-size: 12px; color: #a0aec0; margin-top: 8px; text-align: right; }

        /* ── Rating block ── */
        .rating-block {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #f0f4f8;
        }
        .rating-label {
            font-size: 11px;
            font-weight: 700;
            color: #718096;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .stars-row {
            display: flex;
            gap: 4px;
            margin-bottom: 8px;
        }
        .star-btn {
            font-size: 26px;
            cursor: pointer;
            background: none;
            border: none;
            padding: 2px;
            line-height: 1;
            transition: transform 0.1s;
            color: #e2e8f0;
        }
        .star-btn:active { transform: scale(0.9); }
        .star-btn.active { color: #f6ad55; }

        .rating-comment {
            width: 100%;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px 10px;
            font-size: 13px;
            font-family: inherit;
            color: #2d3748;
            resize: none;
            outline: none;
            transition: border-color 0.15s;
            background: #f7fafc;
        }
        .rating-comment:focus { border-color: #63b3ed; background: white; }

        .rating-save-btn {
            margin-top: 8px;
            width: 100%;
            padding: 9px;
            background: #2d8a4e;
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
        }
        .rating-save-btn:hover { background: #276141; }
        .rating-save-btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .rating-saved-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 600;
            color: #38a169;
            margin-top: 6px;
        }

        /* ── Daily totals ── */
        .totals-card {
            background: #1a202c;
            border-radius: 14px;
            padding: 18px;
            margin-top: 8px;
            margin-bottom: 24px;
        }
        .totals-title {
            font-size: 10px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(255,255,255,0.4);
            margin-bottom: 12px;
        }
        .totals-row { display: flex; justify-content: space-between; gap: 8px; }
        .total-item { flex: 1; text-align: center; }
        .total-value { font-size: 20px; font-weight: 900; color: white; line-height: 1; }
        .total-label {
            font-size: 9px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: rgba(255,255,255,0.4);
            margin-top: 4px;
        }
        .total-item.kcal .total-value { color: #fc8181; }
        .total-item.prot .total-value { color: #63b3ed; }
        .total-item.fat  .total-value { color: #f6e05e; }
        .total-item.carb .total-value { color: #68d391; }
        .total-divider { width: 1px; background: rgba(255,255,255,0.08); }

        /* ── Toast ── */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(80px);
            background: #1a202c;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            z-index: 100;
            transition: transform 0.3s ease;
            white-space: nowrap;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        .toast.show { transform: translateX(-50%) translateY(0); }
        .toast.gift { background: linear-gradient(135deg, #9f7aea, #ed8936); }
    </style>
</head>
<body>

<div class="header">
    <div class="header-brand">{{ $order->projectData?->name ?? 'AVOCADO' }} · Меню</div>
    <div class="header-name">{{ $client->name }}</div>
    @if($order->tariff)
        <div class="header-tariff">{{ $order->tariff->name }} · {{ $order->calories }} ккал</div>
    @endif
</div>

{{-- ── Прогрес бар ── --}}
@if($order && $progress['goal'] > 0)
<div class="progress-banner {{ $order->reward_unlocked ? 'reward-unlocked' : '' }}" id="progress-banner">
    @if($order->reward_given)
        <div class="reward-given-msg">✅ Ваш подарунок вже на шляху до вас!</div>
    @elseif($order->reward_unlocked)
        <div class="reward-unlocked-msg">
            <span class="reward-icon">🎁</span>
            <span class="reward-text">Вітаємо! Ви заслужили подарунок.<br>Менеджер зв'яжеться з вами найближчим часом.</span>
        </div>
    @else
        <div class="progress-top">
            <span class="progress-label">🌟 Ваш прогрес відгуків</span>
            <span class="progress-count" id="progress-count">{{ $progress['completed'] }} / {{ $progress['goal'] }}</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" id="progress-fill"
                 style="width: {{ $progress['goal'] > 0 ? round(($progress['completed'] / $progress['goal']) * 100) : 0 }}%">
            </div>
        </div>
        <div class="progress-dots" id="progress-dots">
            @for($i = 1; $i <= $progress['goal']; $i++)
                <div class="progress-dot {{ $i <= $progress['completed'] ? 'done' : '' }}" data-day="{{ $i }}">
                    {{ $i <= $progress['completed'] ? '✓' : $i }}
                </div>
            @endfor
        </div>
        @if($isToday)
            <div class="progress-hint">
                @if($progress['completed'] < $progress['goal'])
                    Оцініть всі страви сьогодні — і отримаєте 🎁 після {{ $progress['goal'] }} днів!
                @endif
            </div>
        @endif
    @endif
</div>
@endif

{{-- ── Навігація по датах ── --}}
<div class="date-nav">
    @if($hasPrev)
        <a href="{{ route('menu.show', $token) }}?date={{ $prevDate->format('Y-m-d') }}" class="date-nav-btn">
            ← {{ $prevDate->format('d.m') }}
        </a>
    @else
        <span class="date-nav-btn disabled">← —</span>
    @endif

    <div class="date-center">
        <div class="date-main">
            @if($date->isToday()) Сьогодні, {{ $date->isoFormat('D MMMM') }}
            @elseif($date->isYesterday()) Вчора, {{ $date->isoFormat('D MMMM') }}
            @elseif($date->isTomorrow()) Завтра, {{ $date->isoFormat('D MMMM') }}
            @else {{ $date->isoFormat('D MMMM') }}
            @endif
        </div>
        <div class="date-sub">{{ $date->isoFormat('dddd') }}</div>
    </div>

    @if($hasNext)
        <a href="{{ route('menu.show', $token) }}?date={{ $nextDate->format('Y-m-d') }}" class="date-nav-btn">
            {{ $nextDate->format('d.m') }} →
        </a>
    @else
        <span class="date-nav-btn disabled">— →</span>
    @endif
</div>

<div class="content">

    @if(empty($items))
        <div class="empty-state">
            <div class="empty-state-icon">🍽</div>
            <div class="empty-state-title">Меню на цей день відсутнє</div>
            <div class="empty-state-text">Цей день не входить у ваше замовлення або меню ще не сформоване.</div>
        </div>
    @else

        @php $groupedItems = collect($items)->groupBy('meal'); @endphp

        @foreach($groupedItems as $mealName => $dishes)
            <div class="meal-section">
                <div class="meal-label">{{ $mealName }}</div>

                @foreach($dishes as $item)
                    @php
                        $hasChanges  = $order->replacements->where('dish_id', $item['dish_id'])->isNotEmpty();
                        $savedRating = $todayRatings[$item['dish_id']] ?? null;
                        $savedStars  = $savedRating ? $savedRating['stars'] : 0;
                        $savedComment = $savedRating ? ($savedRating['comment'] ?? '') : '';
                    @endphp

                    <div class="dish-card" id="card-{{ $item['dish_id'] }}">
                        {{-- Клікабельна частина — посилання на деталі --}}
                        <a href="{{ route('menu.dish', [$token, $item['dish_id']]) }}?date={{ $date->format('Y-m-d') }}"
                           class="dish-card-link">
                            <div class="dish-card-top">
                                <div class="dish-name">{{ $item['dish_name'] }}</div>
                                <div class="dish-weight">{{ $item['weight'] }}г</div>
                            </div>
                            <div class="dish-kbju">
                                <div class="kbju-item kcal">
                                    <span class="kbju-value">{{ round($item['kcal']) }}</span>
                                    <span class="kbju-label">ккал</span>
                                </div>
                                <div class="kbju-item prot">
                                    <span class="kbju-value">{{ round($item['prot'], 1) }}</span>
                                    <span class="kbju-label">білки</span>
                                </div>
                                <div class="kbju-item fat">
                                    <span class="kbju-value">{{ round($item['fat'], 1) }}</span>
                                    <span class="kbju-label">жири</span>
                                </div>
                                <div class="kbju-item carb">
                                    <span class="kbju-value">{{ round($item['carb'], 1) }}</span>
                                    <span class="kbju-label">вуглев.</span>
                                </div>
                            </div>
                            @if($hasChanges)
                                <div class="dish-change-badge">⚡ Індивідуальний склад</div>
                            @endif
                            <div class="dish-arrow">Склад →</div>
                        </a>

                        {{-- Блок рейтингу — тільки сьогодні --}}
                        @if($isToday && !$order->reward_given)
                        <div class="rating-block" data-dish-id="{{ $item['dish_id'] }}">
                            <div class="rating-label">Ваша оцінка страви</div>
                            <div class="stars-row" data-dish-id="{{ $item['dish_id'] }}">
                                @for($s = 1; $s <= 5; $s++)
                                    <button class="star-btn {{ $s <= $savedStars ? 'active' : '' }}"
                                            data-value="{{ $s }}"
                                            onclick="setStar({{ $item['dish_id'] }}, {{ $s }})">★</button>
                                @endfor
                            </div>
                            <textarea
                                class="rating-comment"
                                id="comment-{{ $item['dish_id'] }}"
                                placeholder="Коментар (необов'язково)..."
                                rows="2"
                            >{{ $savedComment }}</textarea>
                            <button class="rating-save-btn"
                                    id="save-btn-{{ $item['dish_id'] }}"
                                    onclick="saveRating({{ $item['dish_id'] }})"
                                    {{ $savedStars === 0 ? 'disabled' : '' }}>
                                {{ $savedStars > 0 ? '✓ Збережено' : 'Зберегти оцінку' }}
                            </button>
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach

        {{-- Підсумок дня --}}
        <div class="totals-card">
            <div class="totals-title">Разом за день</div>
            <div class="totals-row">
                <div class="total-item kcal">
                    <div class="total-value">{{ round($totals['kcal']) }}</div>
                    <div class="total-label">ккал</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item prot">
                    <div class="total-value">{{ round($totals['prot'], 1) }}</div>
                    <div class="total-label">білки г</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item fat">
                    <div class="total-value">{{ round($totals['fat'], 1) }}</div>
                    <div class="total-label">жири г</div>
                </div>
                <div class="total-divider"></div>
                <div class="total-item carb">
                    <div class="total-value">{{ round($totals['carb'], 1) }}</div>
                    <div class="total-label">вуглев. г</div>
                </div>
            </div>
        </div>

    @endif
</div>

{{-- Toast --}}
<div class="toast" id="toast"></div>

<script>
    const TOKEN          = '{{ $token }}';
    const RATE_URL       = '{{ route("menu.rate", $token) }}';
    const CSRF           = document.querySelector('meta[name="csrf-token"]').content;
    const REWARDS_ENABLED = {{ $rewardsEnabled ? 'true' : 'false' }};

    // Поточні зірки для кожної страви
    const selectedStars = {};

    @foreach($items as $item)
    selectedStars[{{ $item['dish_id'] }}] = {{ $todayRatings[$item['dish_id']]['stars'] ?? 0 }};
    @endforeach

    function setStar(dishId, value) {
        selectedStars[dishId] = value;

        // Оновлюємо відображення зірок
        const row = document.querySelector(`.stars-row[data-dish-id="${dishId}"]`);
        if (!row) return;
        row.querySelectorAll('.star-btn').forEach(btn => {
            btn.classList.toggle('active', parseInt(btn.dataset.value) <= value);
        });

        // Розблоковуємо кнопку збереження
        const btn = document.getElementById(`save-btn-${dishId}`);
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Зберегти оцінку';
        }
    }

    async function saveRating(dishId) {
        const stars   = selectedStars[dishId];
        const comment = document.getElementById(`comment-${dishId}`)?.value || '';
        const btn     = document.getElementById(`save-btn-${dishId}`);

        if (!stars || stars < 1) return;

        btn.disabled    = true;
        btn.textContent = 'Збереження...';

        try {
            const res = await fetch(RATE_URL, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF,
                },
                body: JSON.stringify({ dish_id: dishId, stars, comment }),
            });

            const data = await res.json();

            if (data.ok) {
                btn.textContent = '✓ Збережено';

                if (REWARDS_ENABLED && data.progress && data.progress.reward) {
                    showRewardBanner();
                    if (data.reward_just_unlocked) {
                        showToast('🎁 Ви отримали нагороду! Менеджер зв\'яжеться з вами.', true);
                    }
                } else {
                    if (REWARDS_ENABLED) updateProgress(data.progress);
                    showToast('✓ Оцінку збережено');
                }
            } else {
                btn.disabled    = false;
                btn.textContent = 'Спробувати ще раз';
            }
        } catch (e) {
            btn.disabled    = false;
            btn.textContent = 'Помилка, спробуйте ще';
        }
    }

    function updateProgress(progress) {
        const fill  = document.getElementById('progress-fill');
        const count = document.getElementById('progress-count');
        const dots  = document.getElementById('progress-dots');

        if (!fill || !count || !dots) return;

        const pct = progress.goal > 0 ? Math.round((progress.completed / progress.goal) * 100) : 0;
        fill.style.width = pct + '%';
        count.textContent = progress.completed + ' / ' + progress.goal;

        dots.querySelectorAll('.progress-dot').forEach(dot => {
            const day = parseInt(dot.dataset.day);
            if (day <= progress.completed) {
                dot.classList.add('done');
                dot.textContent = '✓';
            }
        });
    }

    function showRewardBanner() {
        const banner = document.getElementById('progress-banner');
        if (!banner) return;

        banner.className = 'progress-banner reward-unlocked';
        banner.innerHTML = `
            <div class="reward-unlocked-msg">
                <span class="reward-icon">🎁</span>
                <span class="reward-text">Вітаємо! Ви заслужили подарунок.<br>Менеджер зв'яжеться з вами найближчим часом.</span>
            </div>
        `;

        // Плавно прокручуємо до банера
        banner.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function showToast(msg, isGift = false) {
        const toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.className   = 'toast' + (isGift ? ' gift' : '');
        toast.classList.add('show');
        setTimeout(() => toast.classList.remove('show'), 3000);
    }
</script>

</body>
</html>
