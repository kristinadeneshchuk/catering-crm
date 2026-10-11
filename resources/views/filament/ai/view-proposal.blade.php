<x-filament-panels::page>
    @php($p = $this->record)
    <x-filament::section>
        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:14px;">
            <div><b>Агент:</b> {{ $p->agent->name }}</div>
            <div><b>Тип:</b> {{ \App\Models\AiProposal::TYPES[$p->type] ?? $p->type }}</div>
            <div><b>Статус:</b> {{ \App\Models\AiProposal::STATUSES[$p->status] ?? $p->status }}</div>
            @if($p->client)<div><b>Клієнт:</b> {{ $p->client->name }} @if($p->order_id)(замовлення #{{ $p->order_id }})@endif</div>@endif
            <div><b>Подано:</b> {{ $p->created_at->format('d.m H:i') }}</div>
            @if($p->reviewer)<div><b>Розглянув:</b> {{ $p->reviewer->name }}, {{ $p->reviewed_at?->format('d.m H:i') }}</div>@endif
        </div>
        @if($p->summary)<div style="margin-top:10px;white-space:pre-line;">{{ $p->summary }}</div>@endif
        @if($p->review_comment)<div style="margin-top:10px;padding:8px 12px;border-radius:8px;background:rgba(59,130,246,.1);"><b>Коментар:</b> {{ $p->review_comment }}</div>@endif
        @if($p->apply_result)
            <div style="margin-top:10px;padding:8px 12px;border-radius:8px;background:{{ $p->status === 'failed' ? 'rgba(239,68,68,.12)' : 'rgba(34,197,94,.12)' }};">
                @if(!empty($p->apply_result['error']))
                    <b>{{ $p->status === 'failed' ? 'Помилка виконання' : 'Не виконано' }}:</b> {{ $p->apply_result['error'] }}
                @elseif($p->type === 'order_replacements')
        @php($items = collect($p->payload['items'] ?? []))
        @php($dn = \App\Models\Dish::whereIn('id', $items->pluck('dish_id')->merge($items->pluck('replacement_dish_id'))->filter())->pluck('name', 'id'))
        @php($in = \App\Models\Ingredient::whereIn('id', $items->pluck('original_product_id')->merge($items->pluck('replacement_product_id'))->filter())->pluck('name', 'id'))
        <x-filament::section heading="Заміни ({{ $items->count() }}){{ ($p->payload['mode'] ?? 'merge') === 'replace' ? ' — замість усіх наявних' : '' }}">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                @foreach($items as $it)
                    <tr style="border-bottom:1px solid rgba(127,127,127,.2);">
                        <td style="padding:4px 6px;">{{ $dn[$it['dish_id']] ?? '#'.$it['dish_id'] }}</td>
                        <td style="padding:4px 6px;">
                            @if(!empty($it['replacement_dish_id'])) уся страва → <b>{{ $dn[$it['replacement_dish_id']] ?? '#'.$it['replacement_dish_id'] }}</b>
                            @elseif(!empty($it['force_approved'])) {{ !empty($it['original_product_id']) ? ($in[$it['original_product_id']] ?? '') : 'страва' }} — <b>примусово одобрено</b>
                            @elseif(!empty($it['replacement_product_id'])) {{ $in[$it['original_product_id']] ?? '#'.($it['original_product_id'] ?? '') }} → <b>{{ $in[$it['replacement_product_id']] ?? '#'.$it['replacement_product_id'] }}</b>
                            @else <b>без:</b> {{ !empty($it['original_product_id']) ? ($in[$it['original_product_id']] ?? '#'.$it['original_product_id']) : 'уся страва' }}
                            @endif
                            @if(!empty($it['comment']))<div style="font-size:12px;opacity:.7;">{{ $it['comment'] }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </table>
        </x-filament::section>
    @elseif($p->type === 'client_message')
                    <b>Надіслано клієнту</b> @if(!empty($p->apply_result['duplicate']))(повтор — уже було надіслано)@endif
                @else
                    <b>Виконано:</b> записів меню {{ $p->apply_result['menu_rows'] ?? 0 }}
                    @if(!empty($p->apply_result['dishes_created'])); нові страви: {{ implode(', ', $p->apply_result['dishes_created']) }}@endif
                    @if(!empty($p->apply_result['dishes_reused'])); уже були в довіднику: {{ implode(', ', $p->apply_result['dishes_reused']) }}@endif
                @endif
            </div>
        @endif
    </x-filament::section>

    @if($p->type === 'individual_week_menu')
        @php($v = $this->menuView())
        <x-filament::section heading="Меню по днях">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                @foreach($v['days'] as $day)
                    <tr><td colspan="3" style="padding:10px 6px 4px;font-weight:800;border-bottom:1px solid rgba(127,127,127,.3);">
                        {{ \Carbon\Carbon::parse($day['date'])->locale('uk')->translatedFormat('l, d.m') }}
                        @if($c = $v['costs'][$day['date']] ?? null)
                            @php($over = $c['pct'] > config('services.ai_agents.food_cost_max_pct', 30))
                            <span style="font-weight:600;font-size:12px;margin-left:8px;color:{{ $over ? '#dc2626' : '#16a34a' }};">собівартість ≈ {{ round($c['cost']) }} грн · {{ $c['pct'] }}% з {{ round($c['price']) }}</span>
                        @endif
                        </td></tr>
                    @foreach($day['meals'] ?? [] as $m)
                        @php($isNew = empty($m['dish_id']))
                        <tr>
                            <td style="padding:3px 6px;width:140px;opacity:.75;">{{ $v['meals'][$m['meal_type_id']] ?? ('#'.$m['meal_type_id']) }}</td>
                            <td style="padding:3px 6px;">
                                {{ $isNew ? ($v['new'][$m['dish_ref']]['name'] ?? $m['dish_ref']) : ($v['dishes'][$m['dish_id']] ?? ('#'.$m['dish_id'])) }}
                                @if($isNew)<span style="font-size:11px;padding:1px 6px;border-radius:6px;background:rgba(234,179,8,.2);margin-left:6px;">нова</span>@endif
                                @if(!empty($m['cooking_note']))<div style="font-size:12px;opacity:.7;">{{ $m['cooking_note'] }}</div>@endif
                            </td>
                            <td style="padding:3px 6px;text-align:right;white-space:nowrap;">{{ !empty($m['weight_grams']) ? $m['weight_grams'].' г' : 'під калораж' }}</td>
                        </tr>
                    @endforeach
                @endforeach
            </table>
        </x-filament::section>

        @if($v['new']->isNotEmpty())
            <x-filament::section heading="Нові страви ({{ $v['new']->count() }})" collapsible>
                @foreach($v['new'] as $d)
                    <div style="margin-bottom:14px;">
                        <div style="font-weight:800;">{{ $d['name'] }} @if(!empty($d['base_weight_g']))<span style="opacity:.6;font-weight:400;">· {{ $d['base_weight_g'] }} г</span>@endif</div>
                        <div style="font-size:12px;opacity:.85;">
                            @foreach($d['ingredients'] ?? [] as $i)
                                {{ !empty($i['child_dish_id']) ? '[НФ] '.($v['pfNames'][$i['child_dish_id']] ?? '#'.$i['child_dish_id']) : ($v['ingNames'][$i['ingredient_id']] ?? '#'.$i['ingredient_id']) }} — {{ $i['net_weight_g'] }} г@if(!$loop->last); @endif
                            @endforeach
                        </div>
                        @if(!empty($d['description']))<div style="font-size:12px;white-space:pre-line;opacity:.7;margin-top:2px;">{{ $d['description'] }}</div>@endif
                    </div>
                @endforeach
            </x-filament::section>
        @endif
    @elseif($p->type === 'client_message')
        {{-- Так, як побачить клієнт. --}}
        <x-filament::section heading="Повідомлення клієнту">
            @if(!empty($p->payload['preview']))<div style="font-size:12px;opacity:.7;margin-bottom:8px;white-space:pre-line;">{{ $p->payload['preview'] }}</div>@endif
            <div style="max-width:520px;padding:10px 14px;border-radius:14px;background:rgba(59,130,246,.12);white-space:pre-wrap;font-size:14px;line-height:1.45;">{{ $p->payload['text'] ?? '' }}</div>
            @foreach($p->payload['attachments'] ?? [] as $f)
                <div style="margin-top:8px;">
                    @if(($f['kind'] ?? '') === 'photo' && !empty($f['path']) && \Illuminate\Support\Facades\Storage::disk('local')->exists($f['path']))
                        <img src="data:{{ $f['mime'] ?? 'image/jpeg' }};base64,{{ base64_encode(\Illuminate\Support\Facades\Storage::disk('local')->get($f['path'])) }}" style="max-width:360px;border-radius:10px;" alt="{{ $f['name'] }}">
                    @else
                        📎 {{ $f['name'] ?? 'файл' }}
                    @endif
                </div>
            @endforeach
            <div style="font-size:12px;opacity:.6;margin-top:10px;">Розмова Inbox #{{ $p->payload['conversation_id'] ?? '—' }} · {{ $p->payload['account'] ?? '' }}</div>
        </x-filament::section>
    @else
        <x-filament::section heading="Дані"><pre style="font-size:12px;white-space:pre-wrap;">{{ json_encode($p->payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) }}</pre></x-filament::section>
    @endif
</x-filament-panels::page>
