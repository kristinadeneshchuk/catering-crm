<x-filament-panels::page>
    @php($t = $this->record->load(['agent', 'client', 'order', 'creator']))
    <div wire:poll.5s>
        <x-filament::section>
            <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:14px;">
                <div><b>Агент:</b> {{ $t->agent->name }}</div>
                <div><b>Статус:</b> {{ \App\Models\AiAgentTask::STATUSES[$t->status] ?? $t->status }}</div>
                @if($t->client)<div><b>Клієнт:</b> <a href="{{ \App\Filament\Resources\ClientResource::getUrl('edit', ['record' => $t->client_id]) }}" style="text-decoration:underline;">{{ $t->client->name }}</a></div>@endif
                @if($t->order_id)<div><b>Замовлення:</b> <a href="{{ \App\Filament\Resources\OrderResource::getUrl('edit', ['record' => $t->order_id]) }}" style="text-decoration:underline;">#{{ $t->order_id }}</a></div>@endif
                @if($t->period_from)<div><b>Період:</b> {{ $t->period_from->format('d.m') }}–{{ $t->period_to?->format('d.m') }}</div>@endif
                @if($t->due_at)<div><b>Дедлайн:</b> {{ $t->due_at->format('d.m H:i') }}</div>@endif
                @if($t->creator)<div><b>Поставив:</b> {{ $t->creator->name }}</div>@endif
                @if(auth()->user()?->isAdmin() && (float) $t->cost_usd > 0)<div><b>Витрати:</b> ${{ number_format((float) $t->cost_usd, 2) }} · кроків {{ $t->steps }}</div>@endif
            </div>
            @if($t->details)<div style="margin-top:10px;white-space:pre-line;">{{ $t->details }}</div>@endif
            @if($t->status === 'failed' && $t->error)<div style="margin-top:10px;padding:8px 12px;border-radius:8px;background:rgba(239,68,68,.12);">{{ $t->error }}</div>@endif
        </x-filament::section>

        @php($proposals = $t->proposals()->latest('id')->get())
        @if($proposals->isNotEmpty())
            <x-filament::section heading="Пропозиції" style="margin-top:16px;">
                @foreach($proposals as $p)
                    @php($warn = collect($p->checks)->where('level', 'warn')->count())
                    <div style="display:flex;gap:12px;align-items:center;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2);font-size:14px;">
                        <a href="{{ \App\Filament\Resources\AiProposalResource::getUrl('view', ['record' => $p->id]) }}" style="text-decoration:underline;font-weight:600;">#{{ $p->id }} {{ $p->title }}</a>
                        @if($p->version > 1)<span style="opacity:.7;">в.{{ $p->version }}</span>@endif
                        <x-filament::badge :color="['pending' => 'warning', 'applied' => 'success', 'failed' => 'danger', 'rejected' => 'gray', 'changes_requested' => 'info'][$p->status] ?? 'gray'">{{ \App\Models\AiProposal::STATUSES[$p->status] ?? $p->status }}</x-filament::badge>
                        @if($p->checks)<x-filament::badge :color="$warn ? 'warning' : 'success'">{{ $warn ? "перевірки: {$warn} попередж." : 'перевірки ✓' }}</x-filament::badge>@endif
                        @if(($p->cross_check['status'] ?? null) === 'conflict')<x-filament::badge color="danger">конфлікт з перепискою</x-filament::badge>@endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif

        <x-filament::section heading="Хід роботи" style="margin-top:16px;">
            @php($icons = ['step' => '•', 'tool' => '·', 'question' => '❓', 'answer' => '💬', 'note' => '📝', 'error' => '⚠️'])
            <div style="display:flex;flex-direction:column;gap:6px;font-size:14px;">
                @forelse($t->events()->with('user')->get() as $e)
                    @php($human = in_array($e->kind, ['question', 'answer', 'note', 'error'], true))
                    @if($e->kind === 'tool')
                        <details style="opacity:.75;font-size:13px;">
                            <summary>{{ $e->created_at->format('H:i') }} · {{ $e->text }}</summary>
                            <pre style="white-space:pre-wrap;font-size:11px;max-height:240px;overflow:auto;">{{ json_encode($e->data['input'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre>
                        </details>
                    @else
                        <div style="padding:{{ $human ? '8px 12px' : '0' }};border-radius:8px;background:{{ ['question' => 'rgba(245,158,11,.14)', 'answer' => 'rgba(59,130,246,.1)', 'error' => 'rgba(239,68,68,.12)', 'note' => 'rgba(127,127,127,.08)'][$e->kind] ?? 'transparent' }};">
                            <span style="opacity:.6;font-size:12px;">{{ $e->created_at->format('d.m H:i') }}</span>
                            {{ $icons[$e->kind] ?? '' }}
                            @if($e->user)<b>{{ $e->user->name }}:</b>@endif
                            <span style="white-space:pre-line;">{{ $e->text }}</span>
                        </div>
                    @endif
                @empty
                    <div style="opacity:.6;">Агент ще не почав.</div>
                @endforelse
            </div>
        </x-filament::section>
    </div>

    @if($t->isOpen())
        <x-filament::section :heading="$t->status === 'waiting_human' ? 'Агент чекає відповіді' : 'Написати агенту'">
            <form wire:submit="sendReply" style="display:flex;flex-direction:column;gap:8px;">
                <textarea wire:model.live.debounce.500ms="reply" rows="3" style="width:100%;border-radius:8px;padding:8px;border:1px solid rgba(127,127,127,.4);background:transparent;" placeholder="Відповідь або уточнення…"></textarea>
                <div><x-filament::button type="submit">Надіслати</x-filament::button></div>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
