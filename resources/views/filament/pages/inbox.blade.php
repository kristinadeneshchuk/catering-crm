<x-filament-panels::page>
@php
    /** @var \Illuminate\Support\Collection $conversations */
    /** @var ?\App\Models\Conversation $selected */
    /** @var \Illuminate\Support\Collection $messages */
    /** @var array $channelStats */
    $data = $this->getViewData();
    $conversations = $data['conversations'];
    $selected      = $data['selected'];
    $messages      = $data['messages'];
    $channelStats  = $data['channelStats'];

    $channelMeta = [
        'telegram'  => ['label' => 'Telegram',  'icon' => '✈️', 'color' => '#0ea5e9'],
        'instagram' => ['label' => 'Instagram', 'icon' => '📷', 'color' => '#ec4899'],
        'viber'     => ['label' => 'Viber',     'icon' => '🟣', 'color' => '#7c3aed'],
    ];

    $filters = [
        'all'        => 'Усі',
        'unread'     => 'Непрочитані',
        'mine'       => 'Мої',
        'unassigned' => 'Без відповідального',
        'closed'     => 'Закриті',
    ];
@endphp

<div style="display:flex;gap:0.75rem;height:calc(100vh - 12rem);min-height:560px;">

    {{-- ═══════════════════════ ЛІВА КОЛОНКА: список чатів ═══════════════════════ --}}
    <div style="width:340px;flex-shrink:0;display:flex;flex-direction:column;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;">

        {{-- Перемикач каналів --}}
        <div style="padding:0.625rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;gap:0.375rem;flex-wrap:wrap;">
            <button wire:click="setChannelFilter(null)"
                    style="padding:0.3rem 0.6rem;font-size:0.72rem;border-radius:0.375rem;border:1px solid {{ $channelFilter === null ? '#fbbf24' : 'rgba(255,255,255,0.1)' }};background:{{ $channelFilter === null ? 'rgba(251,191,36,0.12)' : 'transparent' }};color:{{ $channelFilter === null ? '#fbbf24' : '#9ca3af' }};font-weight:600;cursor:pointer;">
                Всі канали
            </button>
            @foreach($channelMeta as $ch => $meta)
                <button wire:click="setChannelFilter('{{ $ch }}')"
                        style="padding:0.3rem 0.6rem;font-size:0.72rem;border-radius:0.375rem;border:1px solid {{ $channelFilter === $ch ? $meta['color'] : 'rgba(255,255,255,0.1)' }};background:{{ $channelFilter === $ch ? 'rgba(255,255,255,0.05)' : 'transparent' }};color:{{ $channelFilter === $ch ? $meta['color'] : '#9ca3af' }};font-weight:600;cursor:pointer;display:flex;align-items:center;gap:0.25rem;">
                    <span>{{ $meta['icon'] }}</span>
                    <span>{{ $meta['label'] }}</span>
                    @if(!empty($channelStats[$ch]))
                        <span style="background:#f87171;color:white;font-size:0.6rem;font-weight:700;padding:0 0.3rem;border-radius:100px;min-width:1rem;text-align:center;">{{ $channelStats[$ch] }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Фільтри статусу --}}
        <div style="padding:0.5rem 0.625rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;gap:0.25rem;flex-wrap:wrap;">
            @foreach($filters as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        style="padding:0.25rem 0.55rem;font-size:0.7rem;border-radius:0.375rem;border:none;background:{{ $filter === $key ? 'rgba(251,191,36,0.15)' : 'transparent' }};color:{{ $filter === $key ? '#fbbf24' : '#6b7280' }};font-weight:600;cursor:pointer;">
                    {{ $label }}
                </button>
            @endforeach
        </div>

        {{-- Пошук --}}
        <div style="padding:0.5rem 0.625rem;border-bottom:1px solid rgba(255,255,255,0.06);">
            <input type="text"
                   wire:model.live.debounce.400ms="search"
                   placeholder="Пошук за ім'ям, телефоном, текстом…"
                   style="width:100%;padding:0.4rem 0.6rem;font-size:0.78rem;background:rgba(0,0,0,0.2);border:1px solid rgba(255,255,255,0.08);border-radius:0.375rem;color:#e5e7eb;outline:none;">
        </div>

        {{-- Список діалогів --}}
        <div style="flex:1;overflow-y:auto;">
            @forelse($conversations as $conv)
                @php
                    $client = $conv->clientChannel?->client;
                    $name   = $client?->name
                              ?: $conv->clientChannel?->display_name
                              ?: ('@' . $conv->clientChannel?->username)
                              ?: 'Невідомий';
                    $isActive = $selected && $selected->id === $conv->id;
                    $meta = $channelMeta[$conv->channel] ?? ['icon' => '💬', 'color' => '#9ca3af'];
                @endphp
                <div wire:click="selectConversation({{ $conv->id }})"
                     style="padding:0.7rem 0.85rem;border-bottom:1px solid rgba(255,255,255,0.04);cursor:pointer;display:flex;gap:0.625rem;align-items:flex-start;background:{{ $isActive ? 'rgba(251,191,36,0.06)' : 'transparent' }};border-left:3px solid {{ $isActive ? '#fbbf24' : 'transparent' }};transition:background 0.12s;"
                     onmouseover="this.style.background='{{ $isActive ? 'rgba(251,191,36,0.08)' : 'rgba(255,255,255,0.03)' }}'"
                     onmouseout="this.style.background='{{ $isActive ? 'rgba(251,191,36,0.06)' : 'transparent' }}'">

                    <div style="width:2.25rem;height:2.25rem;border-radius:50%;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:0.85rem;font-weight:700;color:#9ca3af;position:relative;">
                        {{ mb_substr($name, 0, 1) }}
                        <span style="position:absolute;bottom:-2px;right:-2px;font-size:0.7rem;background:#0f172a;border-radius:50%;width:1rem;height:1rem;display:flex;align-items:center;justify-content:center;">{{ $meta['icon'] }}</span>
                    </div>

                    <div style="flex:1;min-width:0;">
                        <div style="display:flex;justify-content:space-between;gap:0.5rem;align-items:baseline;">
                            <span style="font-size:0.82rem;font-weight:700;color:#f1f5f9;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $name }}</span>
                            <span style="font-size:0.66rem;color:#6b7280;flex-shrink:0;">{{ $conv->last_message_at?->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) ?? '—' }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;gap:0.5rem;align-items:center;margin-top:0.2rem;">
                            <span style="font-size:0.72rem;color:#9ca3af;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;">
                                {{ $conv->last_message_preview ?? 'Поки без повідомлень' }}
                            </span>
                            @if($conv->unread_count > 0)
                                <span style="background:#f87171;color:white;font-size:0.65rem;font-weight:700;padding:0.05rem 0.35rem;border-radius:100px;min-width:1.1rem;text-align:center;flex-shrink:0;">{{ $conv->unread_count }}</span>
                            @endif
                        </div>
                        @if($conv->assigned_user_id && $conv->assignedUser)
                            <div style="font-size:0.65rem;color:#6b7280;margin-top:0.2rem;">👤 {{ $conv->assignedUser->name }}</div>
                        @endif
                    </div>
                </div>
            @empty
                <div style="padding:3rem 1rem;text-align:center;">
                    <div style="font-size:2.25rem;color:rgba(255,255,255,0.08);margin-bottom:0.75rem;">💬</div>
                    <p style="font-size:0.82rem;color:#6b7280;font-weight:600;">Діалогів немає</p>
                    <p style="font-size:0.7rem;color:#4b5563;margin-top:0.25rem;">Підключіть месенджер-акаунт у розділі «Система»</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- ═══════════════════════ ЦЕНТР: чат ═══════════════════════ --}}
    <div style="flex:1;display:flex;flex-direction:column;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;min-width:0;">
        @if(! $selected)
            <div style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:0.5rem;padding:2rem;">
                <div style="font-size:3.5rem;color:rgba(255,255,255,0.06);">💬</div>
                <p style="font-size:0.9rem;font-weight:600;color:#6b7280;">Виберіть діалог зліва</p>
                <p style="font-size:0.75rem;color:#4b5563;">Або підключіть перший месенджер-акаунт у налаштуваннях</p>
            </div>
        @else
            @php
                $client = $selected->clientChannel?->client;
                $name   = $client?->name
                          ?: $selected->clientChannel?->display_name
                          ?: ('@' . $selected->clientChannel?->username)
                          ?: 'Невідомий';
                $meta = $channelMeta[$selected->channel] ?? ['icon' => '💬', 'color' => '#9ca3af', 'label' => $selected->channel];
            @endphp

            {{-- Шапка чату --}}
            <div style="padding:0.75rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:0.75rem;">
                <div style="width:2.25rem;height:2.25rem;border-radius:50%;background:rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:700;color:#9ca3af;">
                    {{ mb_substr($name, 0, 1) }}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-size:0.88rem;font-weight:700;color:#f1f5f9;">{{ $name }}</div>
                    <div style="font-size:0.7rem;color:#6b7280;display:flex;align-items:center;gap:0.4rem;">
                        <span>{{ $meta['icon'] }} {{ $meta['label'] }}</span>
                        @if($selected->messengerAccount)
                            <span>·</span>
                            <span>через {{ $selected->messengerAccount->display_name }}</span>
                        @endif
                    </div>
                </div>

                <div style="display:flex;gap:0.375rem;">
                    @if($selected->status === \App\Models\Conversation::STATUS_OPEN)
                        @if($selected->assigned_user_id !== auth()->id())
                            <button wire:click="assignToMe"
                                    style="padding:0.35rem 0.7rem;font-size:0.72rem;border-radius:0.375rem;border:1px solid rgba(251,191,36,0.4);background:rgba(251,191,36,0.08);color:#fbbf24;font-weight:600;cursor:pointer;">
                                Взяти собі
                            </button>
                        @endif
                        <button wire:click="closeConversation"
                                style="padding:0.35rem 0.7rem;font-size:0.72rem;border-radius:0.375rem;border:1px solid rgba(255,255,255,0.1);background:transparent;color:#9ca3af;font-weight:600;cursor:pointer;">
                            Закрити
                        </button>
                    @else
                        <button wire:click="reopenConversation"
                                style="padding:0.35rem 0.7rem;font-size:0.72rem;border-radius:0.375rem;border:1px solid rgba(74,222,128,0.4);background:rgba(74,222,128,0.08);color:#4ade80;font-weight:600;cursor:pointer;">
                            Відновити
                        </button>
                    @endif
                </div>
            </div>

            {{-- Стрічка повідомлень --}}
            <div style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:0.5rem;">
                @forelse($messages as $msg)
                    @php $out = $msg->isOutbound(); @endphp
                    <div style="display:flex;justify-content:{{ $out ? 'flex-end' : 'flex-start' }};">
                        <div style="max-width:70%;padding:0.5rem 0.75rem;border-radius:0.75rem;background:{{ $out ? 'rgba(251,191,36,0.12)' : 'rgba(255,255,255,0.06)' }};border:1px solid {{ $out ? 'rgba(251,191,36,0.25)' : 'rgba(255,255,255,0.08)' }};">
                            @if($msg->replyTo)
                                <div style="padding:0.3rem 0.5rem;border-left:2px solid rgba(255,255,255,0.2);background:rgba(0,0,0,0.15);border-radius:0.25rem;margin-bottom:0.4rem;font-size:0.7rem;color:#9ca3af;">
                                    {{ \Illuminate\Support\Str::limit($msg->replyTo->text, 60) }}
                                </div>
                            @endif

                            @if($msg->type === \App\Models\Message::TYPE_TEXT && $msg->text)
                                <div style="font-size:0.82rem;color:#f1f5f9;white-space:pre-wrap;word-wrap:break-word;">{{ $msg->text }}</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_IMAGE)
                                <div style="font-size:0.75rem;color:#9ca3af;">📷 Зображення</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_VIDEO)
                                <div style="font-size:0.75rem;color:#9ca3af;">🎬 Відео</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_AUDIO)
                                <div style="font-size:0.75rem;color:#9ca3af;">🎙 Голосове</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_DOCUMENT)
                                <div style="font-size:0.75rem;color:#9ca3af;">📎 Документ</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_STICKER)
                                <div style="font-size:1.5rem;">🎨</div>
                            @elseif($msg->type === \App\Models\Message::TYPE_LOCATION)
                                <div style="font-size:0.75rem;color:#9ca3af;">📍 Локація</div>
                            @endif

                            <div style="font-size:0.62rem;color:#6b7280;margin-top:0.25rem;display:flex;justify-content:flex-end;gap:0.3rem;align-items:center;">
                                <span>{{ $msg->created_at->format('H:i') }}</span>
                                @if($out)
                                    @if($msg->status === \App\Models\Message::STATUS_PENDING)<span title="Відправляється">⏳</span>
                                    @elseif($msg->status === \App\Models\Message::STATUS_SENT)<span title="Надіслано">✓</span>
                                    @elseif($msg->status === \App\Models\Message::STATUS_DELIVERED)<span title="Доставлено">✓✓</span>
                                    @elseif($msg->status === \App\Models\Message::STATUS_READ)<span title="Прочитано" style="color:#0ea5e9;">✓✓</span>
                                    @elseif($msg->status === \App\Models\Message::STATUS_FAILED)<span title="Помилка" style="color:#f87171;">⚠</span>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div style="margin:auto;text-align:center;color:#4b5563;font-size:0.8rem;">Поки що немає повідомлень</div>
                @endforelse
            </div>

            {{-- Поле вводу --}}
            @if($selected->status === \App\Models\Conversation::STATUS_OPEN)
                <form wire:submit.prevent="sendMessage"
                      style="padding:0.625rem;border-top:1px solid rgba(255,255,255,0.06);display:flex;gap:0.5rem;align-items:flex-end;">
                    <textarea wire:model="messageDraft"
                              wire:keydown.enter.prevent="sendMessage"
                              placeholder="Введіть повідомлення… Enter — відправити, Shift+Enter — новий рядок"
                              rows="1"
                              style="flex:1;padding:0.55rem 0.75rem;font-size:0.82rem;background:rgba(0,0,0,0.2);border:1px solid rgba(255,255,255,0.08);border-radius:0.5rem;color:#e5e7eb;outline:none;resize:none;max-height:8rem;"></textarea>
                    <button type="submit"
                            style="padding:0.55rem 1rem;font-size:0.78rem;border-radius:0.5rem;border:none;background:#fbbf24;color:#0f172a;font-weight:700;cursor:pointer;">
                        Надіслати
                    </button>
                </form>
            @else
                <div style="padding:0.85rem 1rem;text-align:center;font-size:0.78rem;color:#6b7280;border-top:1px solid rgba(255,255,255,0.06);background:rgba(0,0,0,0.15);">
                    Діалог закритий. «Відновити», щоб продовжити переписку.
                </div>
            @endif
        @endif
    </div>

    {{-- ═══════════════════════ ПРАВА КОЛОНКА: картка клієнта ═══════════════════════ --}}
    <div style="width:300px;flex-shrink:0;display:flex;flex-direction:column;background:rgba(255,255,255,0.02);border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;overflow:hidden;">
        @if(! $selected)
            <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center;">
                <p style="font-size:0.78rem;color:#4b5563;">Тут буде картка клієнта</p>
            </div>
        @else
            @php
                $client    = $selected->clientChannel?->client;
                $orders    = $client?->orders ?? collect();
                $lastOrder = $orders->first();
            @endphp

            <div style="padding:1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                @if($client)
                    <div style="font-size:1rem;font-weight:700;color:#f1f5f9;margin-bottom:0.4rem;">{{ $client->name }}</div>
                    @if($client->phone)
                        <div style="font-size:0.75rem;color:#9ca3af;margin-bottom:0.2rem;">📞 {{ $client->phone }}</div>
                    @endif
                    @if($client->email)
                        <div style="font-size:0.75rem;color:#9ca3af;margin-bottom:0.2rem;">✉ {{ $client->email }}</div>
                    @endif
                    <div style="font-size:0.72rem;color:#6b7280;margin-top:0.5rem;">
                        Баланс: <span style="color:{{ $client->balance > 0 ? '#4ade80' : '#9ca3af' }};font-weight:600;">{{ number_format((float)$client->balance, 2, '.', ' ') }} грн</span>
                    </div>
                    <a href="{{ \App\Filament\Resources\ClientResource::getUrl('edit', ['record' => $client->id]) }}"
                       style="display:inline-block;margin-top:0.6rem;padding:0.35rem 0.7rem;font-size:0.7rem;border-radius:0.375rem;background:rgba(255,255,255,0.06);color:#fbbf24;font-weight:600;text-decoration:none;">
                        Відкрити картку →
                    </a>
                @else
                    <div style="font-size:0.85rem;font-weight:600;color:#f1f5f9;margin-bottom:0.3rem;">
                        {{ $selected->clientChannel?->display_name ?? 'Невідомий контакт' }}
                    </div>
                    @if($selected->clientChannel?->username)
                        <div style="font-size:0.72rem;color:#9ca3af;">@{{ $selected->clientChannel->username }}</div>
                    @endif
                    <div style="margin-top:0.6rem;padding:0.5rem 0.6rem;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.2);border-radius:0.375rem;font-size:0.7rem;color:#fca5a5;">
                        Контакт ще не зматчений з клієнтом CRM
                    </div>
                @endif
            </div>

            {{-- ───────── Конструктор замовлення ───────── --}}
            @if($client)
                @php
                    $quote    = $this->builderOpen ? $this->builderQuote() : null;
                    $tariffs  = $this->builderOpen ? $this->builderTariffs() : collect();
                    $calories = $this->builderOpen ? $this->builderCalorieOptions() : collect();
                @endphp

                <div style="padding:0.85rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                    @if(! $this->builderOpen)
                        <button type="button" wire:click="openBuilder"
                                style="width:100%;padding:0.5rem;font-size:0.75rem;font-weight:700;border-radius:0.5rem;background:#fbbf24;color:#1f2937;border:none;cursor:pointer;">
                            + Оформити замовлення
                        </button>
                    @else
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.6rem;">
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;font-weight:700;">Нове замовлення</div>
                            <button type="button" wire:click="closeBuilder"
                                    style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:0.9rem;line-height:1;">×</button>
                        </div>

                        {{-- Бренд береться з акаунта, у який написали. Міняти — як виняток. --}}
                        <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Бренд</label>
                        <select wire:model.live="builderProject" style="width:100%;margin-bottom:0.5rem;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            <option value="">— оберіть —</option>
                            @foreach(\App\Models\Project::where('is_active', true)->orderBy('name')->get() as $p)
                                <option value="{{ $p->slug }}">{{ $p->name }}</option>
                            @endforeach
                        </select>

                        <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Тариф</label>
                        <select wire:model.live="builderTariffId" @disabled(! $this->builderProject)
                                style="width:100%;margin-bottom:0.5rem;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            <option value="">— оберіть —</option>
                            @foreach($tariffs as $t)
                                <option value="{{ $t->id }}">{{ $t->name }}@if($t->min_days) (від {{ $t->min_days }} дн.)@endif</option>
                            @endforeach
                        </select>

                        <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Калорійність</label>
                        <select wire:model.live="builderCalories" @disabled(! $this->builderTariffId)
                                style="width:100%;margin-bottom:0.5rem;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            <option value="">— оберіть —</option>
                            @foreach($calories as $c)
                                <option value="{{ $c['calories'] }}">{{ $c['label'] }} — {{ number_format($c['price_per_day'], 0, '.', ' ') }} ₴/день</option>
                            @endforeach
                        </select>

                        <div style="display:flex;gap:0.4rem;margin-bottom:0.5rem;">
                            <div style="flex:1;">
                                <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Днів</label>
                                <input type="number" min="1" wire:model.live.debounce.400ms="builderDays"
                                       style="width:100%;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            </div>
                            <div style="flex:1.4;">
                                <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Старт</label>
                                <input type="date" wire:model.live="builderStart"
                                       style="width:100%;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            </div>
                        </div>

                        <div style="display:flex;gap:0.4rem;margin-bottom:0.5rem;">
                            <div style="flex:1;">
                                <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Знижка, ₴</label>
                                <input type="number" min="0" step="1" wire:model.live.debounce.400ms="builderDiscount"
                                       style="width:100%;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;font-size:0.65rem;color:#6b7280;margin-bottom:0.15rem;">Доставка</label>
                                <select wire:model.live="builderWindow"
                                        style="width:100%;padding:0.35rem;font-size:0.72rem;border-radius:0.375rem;background:rgba(255,255,255,0.05);color:#f1f5f9;border:1px solid rgba(255,255,255,0.1);">
                                    <option value="morning">Ранок</option>
                                    <option value="evening">Вечір</option>
                                </select>
                            </div>
                        </div>

                        {{-- Суму рахує CRM, не ця сторінка. --}}
                        @if($quote)
                            <div style="padding:0.6rem;border-radius:0.5rem;background:rgba(74,222,128,0.07);border:1px solid rgba(74,222,128,0.2);margin-bottom:0.5rem;">
                                <div style="font-size:0.68rem;color:#9ca3af;">{{ $quote['calorie_range']['name'] }}</div>
                                <div style="font-size:0.72rem;color:#9ca3af;margin-top:0.25rem;">
                                    {{ number_format($quote['price_per_day'], 0, '.', ' ') }} ₴ × {{ $quote['days'] }} дн. =
                                    {{ number_format($quote['subtotal'], 2, '.', ' ') }} ₴
                                </div>
                                @if($quote['discount'] > 0)
                                    <div style="font-size:0.72rem;color:#fbbf24;margin-top:0.15rem;">
                                        Знижка: −{{ number_format($quote['discount'], 2, '.', ' ') }} ₴
                                    </div>
                                @endif
                                <div style="font-size:0.95rem;font-weight:700;color:#4ade80;margin-top:0.3rem;">
                                    До сплати: {{ number_format($quote['total'], 2, '.', ' ') }} ₴
                                </div>
                            </div>

                            <button type="button" wire:click="createOrderFromChat" wire:loading.attr="disabled"
                                    style="width:100%;padding:0.5rem;font-size:0.75rem;font-weight:700;border-radius:0.5rem;background:#4ade80;color:#052e16;border:none;cursor:pointer;">
                                Створити замовлення
                            </button>
                        @elseif($this->builderError)
                            <div style="padding:0.5rem 0.6rem;border-radius:0.375rem;background:rgba(248,113,113,0.08);border:1px solid rgba(248,113,113,0.2);font-size:0.7rem;color:#fca5a5;">
                                {{ $this->builderError }}
                            </div>
                        @else
                            <div style="font-size:0.7rem;color:#6b7280;text-align:center;padding:0.4rem;">
                                Оберіть тариф і калорійність
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            @if($lastOrder)
                <div style="padding:0.85rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                    <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;font-weight:700;margin-bottom:0.45rem;">Поточне замовлення</div>
                    <div style="font-size:0.82rem;color:#f1f5f9;font-weight:600;">#{{ $lastOrder->id }}</div>
                    @if($lastOrder->start_date && $lastOrder->end_date)
                        <div style="font-size:0.72rem;color:#9ca3af;margin-top:0.2rem;">
                            {{ \Carbon\Carbon::parse($lastOrder->start_date)->format('d.m') }}
                            —
                            {{ \Carbon\Carbon::parse($lastOrder->end_date)->format('d.m.Y') }}
                        </div>
                    @endif
                    @if(isset($lastOrder->final_price))
                        <div style="font-size:0.72rem;color:#9ca3af;margin-top:0.2rem;">
                            {{ number_format((float)$lastOrder->final_price, 2, '.', ' ') }} грн ·
                            <span style="color:{{ $lastOrder->is_paid ? '#4ade80' : '#f87171' }};">
                                {{ $lastOrder->is_paid ? 'Оплачено' : 'Не оплачено' }}
                            </span>
                        </div>
                    @endif
                </div>
            @endif

            @if($client && $client->target_kcal)
                <div style="padding:0.7rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);font-size:0.72rem;color:#9ca3af;">
                    🎯 Цільова калорійність: <span style="color:#f1f5f9;font-weight:600;">{{ $client->target_kcal }} ккал</span>
                </div>
            @endif

            @if($client && $client->delivery_comment)
                <div style="padding:0.7rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                    <div style="font-size:0.65rem;text-transform:uppercase;color:#6b7280;font-weight:700;margin-bottom:0.25rem;">Коментар до доставки</div>
                    <div style="font-size:0.72rem;color:#9ca3af;">{{ $client->delivery_comment }}</div>
                </div>
            @endif

            @if($client && $client->manager_comment)
                <div style="padding:0.7rem 1rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                    <div style="font-size:0.65rem;text-transform:uppercase;color:#6b7280;font-weight:700;margin-bottom:0.25rem;">Внутрішній коментар</div>
                    <div style="font-size:0.72rem;color:#9ca3af;">{{ $client->manager_comment }}</div>
                </div>
            @endif

            <div style="padding:0.7rem 1rem;font-size:0.65rem;color:#4b5563;text-align:center;">
                ID діалогу: {{ $selected->id }}
            </div>
        @endif
    </div>

</div>
</x-filament-panels::page>
