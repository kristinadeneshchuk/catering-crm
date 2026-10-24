<?php

namespace App\Services\Agents;

use App\Models\AiAgent;
use App\Models\AiProposal;
use App\Models\User;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Пропозиції агентів: подача → погодження людиною (CRM або кнопки в Telegram)
 * → CRM виконує сама. Агент у бойові дані напряму не пише.
 */
class ProposalDesk
{
    public const CALLBACK = 'aip';

    public function __construct(private TelegramService $telegram) {}

    /** Обробник типу: перевірка, виконання, опис. */
    public function handler(string $type): IndividualWeekMenu|ClientMessage|OrderReplacements
    {
        return match ($type) {
            'individual_week_menu' => app(IndividualWeekMenu::class),
            'client_message'       => app(ClientMessage::class),
            'order_replacements'   => app(OrderReplacements::class),
            default => throw new InvalidArgumentException("Невідомий тип пропозиції «{$type}»"),
        };
    }

    /**
     * @return array{proposal: ?AiProposal, errors: string[]}
     */
    public function submit(AiAgent $agent, array $data): array
    {
        $type = (string) ($data['type'] ?? '');
        if (!isset(AiProposal::TYPES[$type])) return ['proposal' => null, 'errors' => ["type: невідомий тип «{$type}»"]];
        if (!$agent->mayPropose($type)) return ['proposal' => null, 'errors' => ["агенту не дозволено тип «{$type}»"]];

        $payload = (array) ($data['payload'] ?? []);
        $handler = $this->handler($type);
        // Тіньова задача: пропозиція лише для порівняння — без погодження, Telegram і блокування дат.
        $shadow = !empty($data['task_id']) && (bool) (\App\Models\AiAgentTask::whereKey($data['task_id'])->value('payload')['shadow'] ?? false);
        $errors = array_merge($handler->validate($payload), app(AgentChat::class)->validateFiles((array) ($data['files'] ?? [])));
        if ($errors) return ['proposal' => null, 'errors' => $errors];

        // Меню: кодові перевірки (MenuGuard) — жорсткі помилки повертаємо агенту,
        // попередження показуємо людині. Повтори/собівартість/кухня — уже всередині.
        $checks = null;
        if ($type === 'individual_week_menu') {
            $checks = app(MenuGuard::class)->check($payload);
            if ($guardErrors = MenuGuard::errors($checks)) return ['proposal' => null, 'errors' => $guardErrors];
            if (!$shadow && ($lock = $this->periodLock($agent, $payload))) return ['proposal' => null, 'errors' => [$lock]];
            $payload['_warnings'] = MenuGuard::warnings($checks);
        } elseif (method_exists($handler, 'warnings')) {
            // Повтори й собівартість — підказка людині; збій розрахунку не блокує подачу.
            try {
                $payload['_warnings'] = $handler->warnings($payload);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (method_exists($handler, 'prepare')) $payload = $handler->prepare($payload);

        $order = \App\Models\Order::find($payload['order_id'] ?? null);
        $clientId = $order?->client_id ?? (\App\Models\Client::whereKey($payload['crm_client_id'] ?? 0)->value('id'));
        $proposal = AiProposal::create([
            'ai_agent_id'      => $agent->id,
            'ai_agent_task_id' => $data['task_id'] ?? null,
            'type'             => $type,
            'title'            => (string) ($data['title'] ?? AiProposal::TYPES[$type]),
            'summary'          => trim(($data['summary'] ?? $handler->describe($payload))
                . (($payload['_warnings'] ?? []) ? "\n⚠️ " . implode("\n⚠️ ", $payload['_warnings']) : '')),
            'client_id'        => $clientId,
            'order_id'         => $order?->id,
            'payload'          => $payload,
            'checks'           => $checks,
            'status'           => $shadow ? 'shadow' : 'pending',
        ]);
        if ($shadow) return ['proposal' => $proposal, 'errors' => [], 'warnings' => $payload['_warnings'] ?? []];
        $this->supersede($agent, $proposal);

        // Пропозиція зʼявляється в чаті з агентом (і в Telegram) — з файлами, якщо агент їх додав.
        app(AgentChat::class)->fromAgent($agent, trim($proposal->title . "\n" . $proposal->summary), (array) ($data['files'] ?? []), $proposal);

        return ['proposal' => $proposal, 'errors' => [], 'warnings' => $payload['_warnings'] ?? []];
    }

    /**
     * Блокування «клієнт × період»: поки на ці дати чекає меню іншого агента
     * (сесія на Маку чи серверний агент), друге не приймаємо — щоб двоє не робили одне.
     */
    private function periodLock(AiAgent $agent, array $payload): ?string
    {
        $dates = array_column((array) ($payload['days'] ?? []), 'date');
        if (!$dates || empty($payload['order_id'])) return null;
        $other = AiProposal::query()->with('agent')
            ->where('type', 'individual_week_menu')->where('status', 'pending')
            ->where('order_id', $payload['order_id'])->where('ai_agent_id', '!=', $agent->id)
            ->get()
            ->first(fn (AiProposal $p) => array_intersect($dates, array_column((array) ($p->payload['days'] ?? []), 'date')));

        return $other ? "На ці дати вже чекає погодження меню #{$other->id} від «{$other->agent?->name}» — дочекайтесь рішення або попросіть його відкликати" : null;
    }

    /** Нова версія меню того самого агента на ті самі дати замінює попередню, що ще чекала. */
    private function supersede(AiAgent $agent, AiProposal $new): void
    {
        if ($new->type !== 'individual_week_menu' || !$new->order_id) return;
        $dates = array_column((array) ($new->payload['days'] ?? []), 'date');
        $old = AiProposal::query()
            ->where('type', 'individual_week_menu')->whereIn('status', ['pending', 'changes_requested'])
            ->where('order_id', $new->order_id)->where('ai_agent_id', $agent->id)->whereKeyNot($new->id)
            ->latest('id')->get()
            ->first(fn (AiProposal $p) => array_intersect($dates, array_column((array) ($p->payload['days'] ?? []), 'date')));
        if (!$old) return;

        $new->forceFill(['supersedes_id' => $old->id, 'version' => ($old->version ?? 1) + 1])->save();
        if ($old->status === 'pending') {
            $old->forceFill(['status' => 'rejected', 'review_comment' => "Замінено новою версією #{$new->id}", 'reviewed_at' => now()])->save();
        }
    }

    public function approve(AiProposal $p, ?User $by, bool $force = false): string
    {
        // Повторно — лише «надіслати все одно» після відмови stale.
        $resend = $force && $p->status === 'changes_requested' && ($p->apply_result['code'] ?? null) === 'stale';
        if (!$p->isPending() && !$resend) return 'Уже розглянуто: ' . (AiProposal::STATUSES[$p->status] ?? $p->status);

        $p->forceFill(['reviewed_by' => $by?->id, 'reviewed_at' => now()])->save();
        $p->setRelation('reviewer', $by);

        try {
            $result = $this->handler($p->type)->apply($p->payload, $p, $force);
            $p->forceFill(['status' => 'applied', 'applied_at' => now(), 'apply_result' => $result])->save();
            $this->handOff($p);
            $answer = $p->type === 'client_message' ? '✅ Погоджено й надіслано клієнту' : '✅ Погоджено й виконано';
        } catch (ProposalOutcome $o) {
            $p->forceFill(['status' => $o->status, 'apply_result' => $o->result + ['error' => $o->getMessage()]])->save();
            $answer = '⚠️ ' . $o->getMessage();
        } catch (Throwable $e) {
            report($e);
            $p->forceFill(['status' => 'failed', 'apply_result' => ['error' => $e->getMessage()]])->save();
            $answer = '⚠️ Погоджено, але виконати не вдалося: ' . mb_substr($e->getMessage(), 0, 150);
        }

        $this->refreshTelegram($p, $answer . ($by ? " ({$by->name})" : ''));
        app(AgentChat::class)->system($p->agent, "«{$p->title}»: {$answer}" . ($by ? " ({$by->name})" : ''), $p, $by);

        return $answer;
    }

    public function reject(AiProposal $p, ?User $by, ?string $comment = null, bool $changes = false): string
    {
        if (!$p->isPending()) return 'Уже розглянуто: ' . (AiProposal::STATUSES[$p->status] ?? $p->status);

        $p->forceFill([
            'status'         => $changes ? 'changes_requested' : 'rejected',
            'reviewed_by'    => $by?->id,
            'reviewed_at'    => now(),
            'review_comment' => $comment,
        ])->save();

        $answer = $changes ? '✏️ Відправлено на правки' : '❌ Відхилено';
        $this->refreshTelegram($p, $answer . ($by ? " ({$by->name})" : '') . ($comment ? ": " . e($comment) : ''));
        app(AgentChat::class)->system($p->agent, "«{$p->title}»: {$answer}" . ($by ? " ({$by->name})" : '') . ($comment ? "\n{$comment}" : ''), $p, $by);

        return $answer;
    }

    // --- хто погоджує -------------------------------------------------------

    /**
     * AI_APPROVERS="7:123456789,12:987654321" — id користувача CRM : Telegram id.
     * Порожньо — супер адміни, а в Telegram — чати власників.
     *
     * @return array<int, ?string> user_id => telegram_id
     */
    public function approvers(): array
    {
        $map = [];
        foreach (array_filter(explode(',', (string) config('services.ai_agents.approvers'))) as $pair) {
            [$uid, $tg] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ((int) $uid) $map[(int) $uid] = $tg ?: null;
        }

        if (!$map) {
            $owners = $this->telegram->approverChatIds();
            foreach (array_filter(array_map('intval', explode(',', (string) config('services.finance.super_admin_ids')))) as $i => $uid) {
                $map[$uid] = $owners[$i] ?? null;
            }
        }

        return $map;
    }

    public function mayApprove(?User $user): bool
    {
        return $user && (array_key_exists((int) $user->id, $this->approvers()) || $user->isSuperAdmin());
    }

    public function userByTelegram(string $tgId): ?User
    {
        $uid = array_search($tgId, $this->approvers(), true);

        return $uid ? User::find($uid) : null;
    }

    // --- Telegram -----------------------------------------------------------

    /** Кнопка з Telegram. */
    public function button(string $fromId, string $data): string
    {
        if (!preg_match('/^' . self::CALLBACK . ':(ok|no):(\d+)$/', $data, $m)) return 'Невідома дія.';

        $user = $this->userByTelegram($fromId);
        if (!$user) return 'Погоджувати можуть лише призначені люди.';

        $p = AiProposal::find((int) $m[2]);
        if (!$p) return 'Пропозицію не знайдено.';

        return $m[1] === 'ok' ? $this->approve($p, $user) : $this->reject($p, $user);
    }

    private function refreshTelegram(AiProposal $p, string $status): void
    {
        foreach ((array) $p->telegram_messages as $msg) {
            try {
                $this->telegram->editMessage(
                    (string) $msg['chat_id'], (int) $msg['message_id'],
                    "🤖 <b>" . e($p->agent->name) . "</b>: <b>" . e($p->title) . "</b>\n" . e((string) $p->summary) . "\n\n" . $status,
                );
            } catch (Throwable $e) {
                Log::warning('ai proposal edit failed', ['error' => $e->getMessage()]);
            }
        }
    }

    public function url(AiProposal $p): string
    {
        return \App\Filament\Resources\AiProposalResource::getUrl('view', ['record' => $p]);
    }

    /**
     * Погоджене меню → задача агенту розсилки «надіслати меню клієнту» з файлами,
     * які автор меню додав до пропозиції. Перед відправкою той звіряється з рецензентом.
     */
    private function handOff(AiProposal $p): void
    {
        if ($p->type !== 'individual_week_menu') return;
        $to = \App\Models\AiAgent::where('slug', config('services.ai_agents.menu_handoff_agent'))->where('is_active', true)->first();
        if (!$to) return;

        $msg = \App\Models\AiMessage::where('ai_proposal_id', $p->id)->where('direction', 'agent')->oldest('id')->first();
        $dates = array_column((array) ($p->payload['days'] ?? []), 'date');
        $to->tasks()->create([
            'title'   => 'Надіслати меню клієнту: ' . ($p->client?->name ?? "#{$p->client_id}")
                . ($dates ? ' (' . Carbon::parse(min($dates))->format('d.m') . '–' . Carbon::parse(max($dates))->format('d.m') . ')' : ''),
            'details' => "Меню погоджено. Перед відправкою — звірся з рецензентом меню; потім запропонуй повідомлення клієнту (client_message).",
            'payload' => [
                'proposal_id' => $p->id, 'order_id' => $p->order_id, 'client_id' => $p->client_id, 'dates' => $dates,
                'files' => collect((array) $msg?->attachments)->keys()->map(fn ($i) => ['message_id' => $msg->id, 'index' => $i, 'name' => $msg->attachments[$i]['name']])->all(),
            ],
        ]);
    }
}
