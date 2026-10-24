<?php

namespace App\Services\Agents;

use App\Models\AiAgentReport;
use App\Models\AiProposal;
use App\Models\Client;
use App\Models\ClientDietProfile;
use App\Models\Ingredient;
use App\Models\Order;
use App\Services\TelegramService;
use Illuminate\Support\Facades\DB;

/**
 * Пропозиції агента «Переписки» (docs/tz-ai-agents-in-crm.md §5.3). Кожна несе
 * доказ із чату: evidence {message_id, quote ≤ 200}. Виконує CRM після погодження.
 *
 *  client_exclusion_add  — «не їм / алергія»: продукти + групи; на всі картки людини (дублі);
 *  client_profile_update — «краще ні», «більше», правила, нотатки;
 *  client_contact_update — телефон, адреса, поверх, «де лишати»;
 *  order_day_change      — пауза / перенос / скасування дня: поки вносить менеджер вручну;
 *  client_complaint      — скарга: у звіти власнику (компенсації — лише власник).
 */
class ChatProposals
{
    public const TYPES = [
        'client_exclusion_add'  => 'Виключення з переписки',
        'client_profile_update' => 'Профіль харчування з переписки',
        'client_contact_update' => 'Контакти / адреса з переписки',
        'order_day_change'      => 'Пауза / перенос дня',
        'client_complaint'      => 'Скарга клієнта',
    ];

    private const CONTACT_FIELDS = ['phone', 'address', 'address_entrance', 'address_apartment', 'address_floor', 'delivery_comment'];

    public function __construct(private string $type) {}

    /** @return string[] */
    public function validate(array $p): array
    {
        $e = [];
        $client = Client::find($p['client_id'] ?? 0);
        if (!$client) $e[] = 'client_id: клієнта не знайдено';
        if (blank($p['evidence']['quote'] ?? null) || empty($p['evidence']['message_id'])) {
            $e[] = 'evidence: потрібні message_id і цитата клієнта (до 200 символів)';
        } elseif (mb_strlen((string) $p['evidence']['quote']) > 200) {
            $e[] = 'evidence.quote: не довше 200 символів';
        }

        switch ($this->type) {
            case 'client_exclusion_add':
                $ids = array_map('intval', (array) ($p['ingredient_ids'] ?? []));
                $groups = (array) ($p['group_slugs'] ?? []);
                if (!$ids && !$groups) $e[] = 'вкажіть ingredient_ids або group_slugs';
                if ($ids && Ingredient::whereIn('id', $ids)->count() !== count(array_unique($ids))) $e[] = 'ingredient_ids: є неіснуючі продукти';
                if ($bad = array_diff($groups, array_keys(IngredientGroupCatalog::all()))) $e[] = 'group_slugs: невідомі групи ' . implode(', ', $bad);
                break;
            case 'client_profile_update':
                if (!array_filter([$p['avoid_add'] ?? null, $p['want_more_add'] ?? null, $p['rules_add'] ?? null, $p['notes_add'] ?? null])) $e[] = 'порожня зміна профілю';
                break;
            case 'client_contact_update':
                if (!array_intersect_key($p, array_flip(self::CONTACT_FIELDS))) $e[] = 'вкажіть хоча б одне поле: ' . implode(', ', self::CONTACT_FIELDS);
                break;
            case 'order_day_change':
                if (!in_array($p['action'] ?? '', ['pause', 'move', 'cancel_day', 'resume'], true)) $e[] = 'action: pause | move | cancel_day | resume';
                if (empty($p['dates'])) $e[] = 'dates: дати рівно як написав клієнт (Y-m-d)';
                if (!Order::whereKey($p['order_id'] ?? 0)->where('client_id', $p['client_id'] ?? 0)->exists()) $e[] = 'order_id: замовлення цього клієнта не знайдено';
                break;
            case 'client_complaint':
                if (blank($p['issue'] ?? null)) $e[] = 'issue: суть скарги';
                break;
        }

        return $e;
    }

    public function apply(array $p, ?AiProposal $proposal = null, bool $force = false): array
    {
        return match ($this->type) {
            'client_exclusion_add'  => $this->applyExclusion($p),
            'client_profile_update' => $this->applyProfile($p),
            'client_contact_update' => $this->applyContact($p),
            'order_day_change'      => throw new ProposalOutcome('failed', 'Паузу/перенос поки вносить менеджер у замовленні вручну — пропозиція лише підказує, що й на які дати', ['manual' => true]),
            'client_complaint'      => $this->applyComplaint($p, $proposal),
        };
    }

    public function describe(array $p): string
    {
        $client = Client::find($p['client_id'] ?? 0);
        $what = match ($this->type) {
            'client_exclusion_add'  => 'не можна: ' . collect(Ingredient::whereIn('id', (array) ($p['ingredient_ids'] ?? []))->pluck('name'))
                ->merge(collect((array) ($p['group_slugs'] ?? []))->map(fn ($s) => 'група «' . (IngredientGroupCatalog::all()[$s]['name'] ?? $s) . '»'))->implode(', '),
            'client_profile_update' => 'профіль: ' . collect(['краще ні' => $p['avoid_add'] ?? [], 'більше' => $p['want_more_add'] ?? []])
                ->filter()->map(fn ($v, $k) => $k . ' — ' . collect($v)->map(fn ($x) => is_array($x) ? ($x['text'] ?? $x['group_slug'] ?? '') : $x)->implode(', '))->implode('; ')
                . (!empty($p['rules_add']) ? '; правил: ' . count($p['rules_add']) : '') . (!empty($p['notes_add']) ? '; нотатка' : ''),
            'client_contact_update' => 'контакти: ' . collect(array_intersect_key($p, array_flip(self::CONTACT_FIELDS)))->map(fn ($v, $k) => "{$k}: {$v}")->implode('; '),
            'order_day_change'      => (['pause' => 'пауза', 'move' => 'перенос', 'cancel_day' => 'скасувати день', 'resume' => 'відновити'][$p['action'] ?? ''] ?? '?')
                . ' ' . implode(', ', (array) ($p['dates'] ?? [])) . (!empty($p['new_dates']) ? ' → ' . implode(', ', $p['new_dates']) : ''),
            'client_complaint'      => 'скарга: ' . ($p['issue'] ?? ''),
        };

        return trim(($client?->name ?? 'Клієнт ?') . " (#{$client?->id})\n{$what}\n«" . ($p['evidence']['quote'] ?? '') . '»');
    }

    /**
     * Усі картки тієї самої людини: дублі в CRM (однаковий телефон). Виключення —
     * на всі, бо саме через дублі вони роз'їжджались (Черниш 682/683, Семенов 684/685).
     *
     * @return int[]
     */
    public static function twins(int $clientId): array
    {
        $phone = substr(preg_replace('/\D/', '', (string) Client::whereKey($clientId)->value('phone')), -10);
        if (strlen($phone) < 9) return [$clientId];
        $ids = Client::whereNotNull('phone')->get(['id', 'phone'])
            ->filter(fn ($c) => substr(preg_replace('/\D/', '', (string) $c->phone), -10) === $phone)->pluck('id')->all();

        return array_values(array_unique(array_merge([$clientId], $ids)));
    }

    private function applyExclusion(array $p): array
    {
        $cards = self::twins((int) $p['client_id']);

        return DB::transaction(function () use ($p, $cards) {
            $added = 0;
            foreach ($cards as $cid) {
                $client = Client::find($cid);
                $before = $client->ingredientExclusions()->count();
                $client->ingredientExclusions()->syncWithoutDetaching(array_map('intval', (array) ($p['ingredient_ids'] ?? [])));
                $added += $client->ingredientExclusions()->count() - $before;
                if (!empty($p['group_slugs'])) {
                    $profile = ClientDietProfile::firstOrCreate(['client_id' => $cid], ['mode' => 'cycle_replacements']);
                    $profile->update(['excluded_groups' => array_values(array_unique(array_merge((array) $profile->excluded_groups, $p['group_slugs'])))]);
                }
            }

            return ['cards' => $cards, 'exclusions_added' => $added, 'groups' => $p['group_slugs'] ?? []];
        });
    }

    private function applyProfile(array $p): array
    {
        $profile = ClientDietProfile::firstOrCreate(['client_id' => $p['client_id']], ['mode' => 'cycle_replacements']);
        $merge = fn ($old, $new) => array_values(array_unique(array_merge((array) $old, (array) $new), SORT_REGULAR));
        $profile->update([
            'avoid' => $merge($profile->avoid, array_map(fn ($a) => is_array($a) ? $a : ['text' => $a], (array) ($p['avoid_add'] ?? []))),
            'want_more' => $merge($profile->want_more, $p['want_more_add'] ?? []),
            'rules' => $merge($profile->rules, $p['rules_add'] ?? []),
            'notes' => trim(($profile->notes ? $profile->notes . "\n" : '') . (!empty($p['notes_add']) ? now()->format('d.m') . ': ' . $p['notes_add'] : '')) ?: null,
        ]);

        return ['profile_id' => $profile->id];
    }

    private function applyContact(array $p): array
    {
        $fields = array_intersect_key($p, array_flip(self::CONTACT_FIELDS));
        Client::findOrFail($p['client_id'])->update($fields);

        return ['updated' => array_keys($fields), 'note' => 'Дні доставки, що вже в замовленні, перевірте окремо'];
    }

    private function applyComplaint(array $p, ?AiProposal $proposal): array
    {
        $client = Client::find($p['client_id']);
        $text = "Скарга: {$client?->name} (#{$client?->id})" . (!empty($p['date']) ? ", {$p['date']}" : '') . (!empty($p['dish']) ? ", {$p['dish']}" : '')
            . "\n{$p['issue']}\n«{$p['evidence']['quote']}»";
        $report = AiAgentReport::create([
            'ai_agent_id' => $proposal?->ai_agent_id ?? \App\Models\AiAgent::serverChats()->id,
            'ai_agent_task_id' => $proposal?->ai_agent_task_id,
            'title' => 'Скарга: ' . ($client?->name ?? '?'), 'body' => $text, 'level' => 'warn',
        ]);
        rescue(fn () => app(TelegramService::class)->sendToOwner('⚠️ ' . $text));

        return ['report_id' => $report->id];
    }
}
