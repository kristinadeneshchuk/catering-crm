<x-filament-panels::page>
    <div wire:poll.10s style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap;">
        {{-- Агенти --}}
        <div style="flex:0 0 220px;display:flex;flex-direction:column;gap:6px;">
            @foreach($agents as $a)
                <button wire:click="selectAgent({{ $a->id }})" style="text-align:left;padding:10px 12px;border-radius:10px;border:1px solid rgba(127,127,127,.25);{{ $a->id === $agentId ? 'background:rgba(234,179,8,.15);border-color:#eab308;' : '' }}">
                    <div style="font-weight:700;">🤖 {{ $a->name }}</div>
                    <div style="font-size:12px;opacity:.7;">{{ $a->last_seen_at ? 'на звʼязку '.$a->last_seen_at->diffForHumans() : 'ще не підключався' }}</div>
                    @if($a->pending)<div style="font-size:12px;color:#d97706;font-weight:700;">чекає погодження: {{ $a->pending }}</div>@endif
                </button>
            @endforeach
        </div>

        {{-- Розмова --}}
        <div style="flex:1 1 480px;min-width:0;display:flex;flex-direction:column;gap:10px;">
            @forelse($messages as $m)
                @php($mine = $m->direction === 'human')
                @if($m->direction === 'system')
                    <div style="align-self:center;font-size:12px;opacity:.75;padding:4px 10px;border-radius:8px;background:rgba(127,127,127,.12);white-space:pre-line;">{{ $m->text }} · {{ $m->created_at->format('d.m H:i') }}</div>
                    @continue
                @endif
                <div style="align-self:{{ $mine ? 'flex-end' : 'flex-start' }};max-width:min(640px,92%);">
                    <div style="font-size:11px;opacity:.6;margin:0 4px 2px;{{ $mine ? 'text-align:right;' : '' }}">
                        {{ $mine ? ($m->user?->name ?? 'Ви') : '🤖 '.$m->agent?->name.($m->toAgent ? ' → 🤖 '.$m->toAgent->name : '') }} · {{ $m->created_at->format('d.m H:i') }}
                    </div>
                    <div style="padding:10px 14px;border-radius:14px;white-space:pre-wrap;line-height:1.45;font-size:14px;background:{{ $mine ? 'rgba(59,130,246,.15)' : ($m->toAgent ? 'rgba(168,85,247,.12)' : 'rgba(127,127,127,.12)') }};">{{ $m->text }}
                        @foreach((array) $m->attachments as $i => $f)
                            <div style="margin-top:6px;"><a href="{{ route('ai.file', [$m->id, $i]) }}" target="_blank" style="text-decoration:underline;">📎 {{ $f['name'] }}</a> <span style="font-size:11px;opacity:.6;">{{ round(($f['size'] ?? 0) / 1024) }} КБ</span></div>
                        @endforeach
                    </div>
                    @if($p = $m->proposal)
                        <div style="margin-top:6px;padding:10px 12px;border-radius:12px;border:1px solid rgba(127,127,127,.3);">
                            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center;">
                                <b>{{ \App\Models\AiProposal::TYPES[$p->type] ?? $p->type }}</b>
                                <span style="font-size:12px;padding:2px 8px;border-radius:8px;background:{{ ['pending' => 'rgba(234,179,8,.25)', 'applied' => 'rgba(34,197,94,.2)', 'failed' => 'rgba(239,68,68,.2)'][$p->status] ?? 'rgba(127,127,127,.2)' }};">{{ \App\Models\AiProposal::STATUSES[$p->status] ?? $p->status }}</span>
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;">
                                @if($p->isPending())
                                    <x-filament::button size="sm" color="success" wire:click="approve({{ $p->id }})" wire:confirm="Погодити — CRM одразу виконає.">Погодити</x-filament::button>
                                    <x-filament::button size="sm" color="info" wire:click="changes({{ $p->id }})">Правки (текст унизу)</x-filament::button>
                                    <x-filament::button size="sm" color="danger" wire:click="reject({{ $p->id }})" wire:confirm="Відхилити?">Відхилити</x-filament::button>
                                @endif
                                <x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Resources\AiProposalResource::getUrl('view', ['record' => $p])">Деталі</x-filament::button>
                            </div>
                        </div>
                    @endif
                </div>
            @empty
                <div style="opacity:.6;">Повідомлень ще немає.</div>
            @endforelse

            {{-- Відповідь --}}
            <form wire:submit="send" style="position:sticky;bottom:0;padding-top:8px;display:flex;flex-direction:column;gap:6px;background:inherit;">
                <textarea wire:model="draft" rows="3" placeholder="Написати агенту… (для правок — напишіть тут і натисніть «Правки» на картці)" style="width:100%;border-radius:10px;padding:10px;border:1px solid rgba(127,127,127,.35);background:transparent;"></textarea>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="file" wire:model="uploads" multiple style="font-size:12px;">
                    <x-filament::button type="submit">Надіслати</x-filament::button>
                </div>
            </form>
        </div>
    </div>
</x-filament-panels::page>
