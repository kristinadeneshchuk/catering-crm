<x-filament-panels::page>
    @php
        $state = function ($t) {
            if ($t->status === 'waiting_human') return ['агент питає', '#d97706'];
            if ($t->pending_count) return ['чекає погодження', '#d97706'];
            if (in_array($t->status, ['new', 'in_progress'], true)) return ['працює…', '#2563eb'];
            if ($t->status === 'failed') return ['помилка', '#dc2626'];
            if ($t->status === 'cancelled') return ['скасовано', 'rgba(127,127,127,.8)'];
            return [($t->payload['shadow'] ?? false) ? 'готово · тінь' : 'готово', 'rgba(127,127,127,.8)'];
        };
    @endphp
    <div wire:poll.5s style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        {{-- Розмови --}}
        <div style="flex:0 0 250px;display:flex;flex-direction:column;gap:6px;max-height:78vh;overflow:auto;">
            <x-filament::button wire:click="newConversation" icon="heroicon-o-plus" color="gray">Нова розмова</x-filament::button>
            <div style="display:flex;gap:4px;font-size:12px;margin:4px 0;">
                <button wire:click="$set('filter','all')" style="padding:3px 10px;border-radius:8px;{{ $filter === 'all' ? 'background:rgba(127,127,127,.18);font-weight:600;' : '' }}">Усі</button>
                <button wire:click="$set('filter','mine')" style="padding:3px 10px;border-radius:8px;{{ $filter === 'mine' ? 'background:rgba(127,127,127,.18);font-weight:600;' : '' }}">Чекають мене</button>
            </div>
            @forelse($tasks as $t)
                @php([$label, $color] = $state($t))
                <button wire:click="select({{ $t->id }})" style="text-align:left;padding:8px 10px;border-radius:10px;border:1px solid {{ $t->id === $taskId ? '#3b82f6' : 'rgba(127,127,127,.25)' }};{{ $t->id === $taskId ? 'background:rgba(59,130,246,.08);' : '' }}">
                    <div style="font-weight:600;font-size:13px;">{{ $t->client?->name ? \Illuminate\Support\Str::limit($t->client->name, 26) : $t->title }}</div>
                    <div style="font-size:12px;opacity:.75;">{{ $t->agent?->slug === 'chats' ? 'переписка' : (\App\Models\AiAgentTask::TEMPLATES[$t->template] ?? 'задача') }}{{ $t->period_from ? ' · '.$t->period_from->format('d.m').'–'.$t->period_to?->format('d.m') : '' }}</div>
                    <div style="font-size:12px;color:{{ $color }};">{{ $label }}</div>
                </button>
            @empty
                <div style="font-size:13px;opacity:.6;">Розмов ще немає.</div>
            @endforelse
        </div>

        {{-- Розмова --}}
        <div style="flex:1 1 520px;min-width:0;display:flex;flex-direction:column;gap:10px;">
            @if(!$task)
                <div style="padding:14px 16px;border-radius:12px;background:rgba(127,127,127,.08);font-size:14px;line-height:1.6;">
                    Напишіть агенту звичайними словами, наприклад:<br>
                    · «Склади меню Ковальовій на 27–31.10, вона просить більше риби»<br>
                    · «Перевір переписку з Протасовим»<br>
                    CRM сама знайде клієнта й замовлення. Агент покаже хід роботи, а результат прийде карткою з кнопками.
                </div>
                @if($notice)
                    <div style="align-self:flex-start;max-width:92%;padding:10px 14px;border-radius:14px;background:rgba(127,127,127,.12);">{{ $notice }}</div>
                @endif
                @if($candidates)
                    <div style="display:flex;flex-direction:column;gap:6px;">
                        @foreach($candidates as $i => $c)
                            <x-filament::button size="sm" color="gray" wire:click="pick({{ $i }})" style="justify-content:flex-start;">{{ $c['label'] }}</x-filament::button>
                        @endforeach
                    </div>
                @endif
            @else
                <div style="font-size:13px;opacity:.75;display:flex;gap:12px;flex-wrap:wrap;">
                    <span>🤖 {{ $task->agent->name }}</span>
                    @if($task->client)<a href="{{ \App\Filament\Resources\ClientResource::getUrl('edit', ['record' => $task->client_id]) }}" style="text-decoration:underline;">{{ $task->client->name }}</a>@endif
                    @if($task->order_id)<a href="{{ \App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $task->order_id]) }}" style="text-decoration:underline;">замовлення #{{ $task->order_id }}</a>@endif
                    @if($task->payload['link'] ?? null)<a href="{{ $task->payload['link'] }}" target="_blank" style="text-decoration:underline;">чат в Inbox</a>@endif
                    @if($task->payload['shadow'] ?? false)<span style="color:#d97706;">тіньовий режим — лише для порівняння</span>@endif
                </div>

                @if($task->details)
                    @if(!empty($task->payload['new_message_ids']))
                        {{-- Задачу поставило сортування нового в чаті, а не менеджер --}}
                        <div style="align-self:center;max-width:90%;font-size:13px;padding:6px 12px;border-radius:10px;background:rgba(127,127,127,.12);">📩 Нове в чаті клієнта: {{ trim(preg_replace('/\s*\[[^\]]*\]\s*$/u', '', $task->details)) }}</div>
                    @else
                        @php($said = preg_match('/Дослівно: «(.*)»\s*$/us', $task->details, $m) ? $m[1] : $task->details)
                        <div style="align-self:flex-end;max-width:80%;padding:10px 14px;border-radius:14px;background:rgba(59,130,246,.15);white-space:pre-line;">{{ $said }}<div style="font-size:11px;opacity:.6;margin-top:2px;">{{ $task->creator?->name }} · {{ $task->created_at->format('d.m H:i') }}</div></div>
                    @endif
                @endif

                @foreach($timeline as $item)
                    @if($item['kind'] === 'proposal')
                        @php($p = $item['p'])
                        @php($v = $item['preview'])
                        <div style="align-self:flex-start;max-width:92%;padding:12px 14px;border-radius:14px;border:1px solid {{ $p->status === 'pending' ? '#22c55e' : 'rgba(127,127,127,.3)' }};">
                            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:6px;">
                                <b>{{ $p->title }}</b>
                                <span style="font-size:12px;padding:2px 8px;border-radius:8px;background:{{ ['pending' => 'rgba(234,179,8,.25)', 'applied' => 'rgba(34,197,94,.2)', 'failed' => 'rgba(239,68,68,.2)', 'shadow' => 'rgba(127,127,127,.18)'][$p->status] ?? 'rgba(127,127,127,.2)' }};">{{ \App\Models\AiProposal::STATUSES[$p->status] ?? $p->status }}</span>
                            </div>
                            @if(isset($v['days']))
                                <div style="white-space:pre-line;font-size:14px;margin-bottom:6px;">{{ $v['summary'] }}</div>
                                <div style="font-size:12px;opacity:.8;line-height:1.6;border-top:1px solid rgba(127,127,127,.2);padding-top:6px;">
                                    @foreach(array_slice($v['days'], 0, 3) as $d)<div>{{ $d }}</div>@endforeach
                                    @if(count($v['days']) > 3)<div>…ще {{ count($v['days']) - 3 }} дн.</div>@endif
                                </div>
                                @if($v['checks'])<div style="font-size:12px;margin-top:6px;color:{{ $v['warn'] ? '#d97706' : '#16a34a' }};">{{ $v['checks'] }}</div>@endif
                            @else
                                <div style="white-space:pre-line;font-size:14px;">{{ implode("\n", $v['lines']) }}</div>
                            @endif
                            @if($p->review_comment)<div style="font-size:12px;margin-top:6px;opacity:.8;">Коментар: {{ $p->review_comment }}</div>@endif
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                                @if($p->isPending())
                                    <x-filament::button size="sm" color="success" wire:click="approve({{ $p->id }})" wire:confirm="Погодити — CRM одразу виконає.">{{ $p->type === 'individual_week_menu' ? 'Погодити' : 'Внести' }}</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="changes({{ $p->id }})">Правки (текст унизу)</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="reject({{ $p->id }})" wire:confirm="Відхилити?">{{ $p->type === 'individual_week_menu' ? 'Відхилити' : 'Не треба' }}</x-filament::button>
                                @endif
                                <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Resources\AiProposalResource::getUrl('view', ['record' => $p])">{{ $p->type === 'individual_week_menu' ? 'Усе меню' : 'Деталі' }}</x-filament::button>
                            </div>
                        </div>
                    @elseif(in_array($item['kind'], ['step', 'tool'], true))
                        @continue($item['kind'] === 'tool' && in_array($item['text'], ['progress', 'finish', 'ask_manager'], true))
                        <div style="font-size:12px;opacity:.6;padding-left:4px;">✓ {{ $item['text'] }}</div>
                    @elseif($item['kind'] === 'answer')
                        <div style="align-self:flex-end;max-width:80%;padding:10px 14px;border-radius:14px;background:rgba(59,130,246,.15);white-space:pre-line;">{{ $item['text'] }}<div style="font-size:11px;opacity:.6;margin-top:2px;">{{ $item['user'] }} · {{ $item['at']->format('H:i') }}</div></div>
                    @elseif($item['kind'] === 'question')
                        <div style="align-self:flex-start;max-width:85%;padding:10px 14px;border-radius:14px;background:rgba(234,179,8,.15);white-space:pre-line;">❓ {{ $item['text'] }}</div>
                    @elseif($item['kind'] === 'error')
                        <div style="align-self:flex-start;max-width:85%;padding:10px 14px;border-radius:14px;background:rgba(239,68,68,.12);white-space:pre-line;">⚠️ {{ $item['text'] }}</div>
                    @elseif($item['kind'] === 'note' && !str_starts_with((string) $item['text'], 'Поставив'))
                        <div style="align-self:flex-start;max-width:85%;padding:10px 14px;border-radius:14px;background:rgba(127,127,127,.12);white-space:pre-line;">{{ $item['text'] }}</div>
                    @endif
                @endforeach

                @if(in_array($task->status, ['new', 'in_progress'], true))
                    <div style="font-size:12px;opacity:.6;padding-left:4px;">… працює</div>
                @endif
            @endif
        </div>
    </div>

    {{-- Поле вводу поза автооновленням, щоб не губився набраний текст --}}
    <form wire:submit="send" style="position:sticky;bottom:0;display:flex;gap:8px;align-items:flex-end;padding:8px 0;background:inherit;">
        <textarea wire:model.live.debounce.400ms="draft" rows="2" style="flex:1;border-radius:12px;padding:10px 12px;border:1px solid rgba(127,127,127,.35);background:transparent;"
            placeholder="{{ !$taskId ? 'Напишіть доручення: «склади меню Ковальовій на 27–31.10»' : ($task?->status === 'waiting_human' ? 'Відповідь агенту…' : ($task?->isOpen() ? 'Уточнення агенту…' : 'Що змінити — агент візьме задачу знову…')) }}"
            x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send() }"></textarea>
        <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Надіслати</x-filament::button>
    </form>
</x-filament-panels::page>
