<?php

namespace App\Services\Ops;

use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Inbox\InboxReadClient;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Менеджери за тиждень: як швидко відповідали, скільки продали, що прогавили.
 *
 * Цифри рахує код з чатів Inbox і з CRM. Бали — стартова формула з
 * `config/ops.managers.weights`; власник підправить ваги, а не ШІ.
 */
class ManagerPerformance
{
    public const PHONE = 'з телефону';

    public function __construct(private InboxReadClient $inbox)
    {
    }

    /**
     * @return array{
     *   period: array{from: string, to: string},
     *   managers: array<string, array>,
     *   team: array,
     *   failures: array<int, array{manager: string, kind: string, text: string, link: ?string, at: ?string}>,
     *   inbox_available: bool,
     * }
     */
    public function forWeek(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->endOfDay();

        $chats    = $this->inbox->configured() ? $this->chatMetrics($from, $to) : ['managers' => [], 'failures' => [], 'available' => false];
        $sales    = $this->salesMetrics($from, $to);
        $failures = array_merge($chats['failures'], $this->crmFailures($from, $to));

        $managers = [];

        foreach (array_unique(array_merge(array_keys($chats['managers']), array_keys($sales))) as $name) {
            $managers[$name] = ($chats['managers'][$name] ?? $this->emptyChat()) + ($sales[$name] ?? $this->emptySales());
            $managers[$name]['failures'] = collect($failures)->where('manager', $name)->count();
        }

        $team = $this->team($managers);

        foreach ($managers as $name => &$m) {
            $m['score'] = $this->score($m, $team);
        }
        unset($m);

        uasort($managers, fn ($a, $b) => $b['score'] <=> $a['score']);

        return [
            'period'          => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'managers'        => $managers,
            'team'            => $team,
            'failures'        => $failures,
            'inbox_available' => $chats['available'],
        ];
    }

    // -------------------------------------------------------------------------
    // Чати (Inbox)
    // -------------------------------------------------------------------------

    /**
     * Для кожної репліки клієнта (блок вхідних підряд) — скільки хвилин до
     * відповіді менеджера. Відповідь із телефону через шлюз приходить без
     * імені менеджера: зараховуємо тому, на кого призначений чат, інакше
     * «з телефону».
     */
    private function chatMetrics(Carbon $from, Carbon $to): array
    {
        try {
            $conversations = $this->inbox->conversationsUpdatedSince($from);
        } catch (\Throwable $e) {
            report($e);

            return ['managers' => [], 'failures' => [], 'available' => false];
        }

        $cfg      = config('ops.managers');
        $managers = [];
        $failures = [];

        foreach ($conversations as $row) {
            $conv = $row['conversation'] ?? [];

            if (($conv['chat_type'] ?? 'private') !== 'private' || $this->isStaffChat($conv)) {
                continue;
            }

            $messages = $this->inbox->messages((int) $conv['id']);
            $assigned = $conv['assigned_manager_name'] ?? null;
            $client   = trim(($row['contact']['first_name'] ?? '').' '.($row['contact']['last_name'] ?? '')) ?: 'клієнт';
            $link     = $row['link'] ?? null;

            $pending = null; // початок блоку вхідних без відповіді

            foreach ($messages as $m) {
                // Inbox віддає час в UTC; робочі години — київські.
                $at = Carbon::parse($m['sent_at'])->setTimezone(config('app.timezone'));

                if (($m['direction'] ?? '') === 'in') {
                    $pending ??= $at;

                    continue;
                }

                if (($m['direction'] ?? '') !== 'out' || $pending === null) {
                    continue;
                }

                $name = $m['manager_name'] ?: ($assigned ?: self::PHONE);

                if ($pending->between($from, $to)) {
                    $minutes = $this->responseMinutes($pending, $at, $cfg);

                    $managers[$name] ??= $this->emptyChat();
                    $managers[$name]['replies']++;
                    $managers[$name]['response_minutes'][] = $minutes;

                    if ($minutes > $cfg['unanswered_minutes'] && $this->inWorkHours($pending, $cfg)) {
                        $failures[] = [
                            'manager' => $name, 'kind' => 'slow',
                            'text'    => "{$client} чекав(ла) відповіді ".$this->hours($minutes),
                            'link'    => $link, 'at' => $pending->format('d.m H:i'),
                        ];
                    }
                }

                $pending = null;
            }

            // Хвіст без відповіді на кінець тижня.
            if ($pending !== null && $pending->between($from, $to)) {
                $name = $assigned ?: self::PHONE;
                $managers[$name] ??= $this->emptyChat();
                $managers[$name]['unanswered']++;

                if ($pending->diffInMinutes($to) > $cfg['unanswered_minutes']) {
                    $failures[] = [
                        'manager' => $name, 'kind' => 'unanswered',
                        'text'    => "{$client}: повідомлення без відповіді з ".$pending->format('d.m H:i'),
                        'link'    => $link, 'at' => $pending->format('d.m H:i'),
                    ];
                }
            }

            $chatManager = $assigned ?: ($messages->firstWhere('manager_name')['manager_name'] ?? null);

            if ($chatManager) {
                $managers[$chatManager] ??= $this->emptyChat();
                $managers[$chatManager]['chats']++;
            }
        }

        foreach ($managers as &$m) {
            $times = collect($m['response_minutes'])->sort()->values();
            $m['median_minutes'] = $times->isEmpty() ? null : (float) $times->median();
            $m['p90_minutes']    = $times->isEmpty() ? null : (float) $times->get((int) floor($times->count() * 0.9 - 0.01)) ;
            $m['within_sla']     = $times->isEmpty() ? null : (int) round($times->filter(fn ($t) => $t <= $cfg['sla_minutes'])->count() / $times->count() * 100);
            unset($m['response_minutes']);
        }
        unset($m);

        return ['managers' => $managers, 'failures' => $failures, 'available' => true];
    }

    /**
     * Хвилини очікування «за правилами»: уночі (поза робочими годинами) відлік
     * починається з початку робочого дня.
     */
    private function responseMinutes(Carbon $askedAt, Carbon $answeredAt, array $cfg): int
    {
        $start = $askedAt->copy();

        if (! $this->inWorkHours($askedAt, $cfg)) {
            $start = $askedAt->hour >= $cfg['work_to']
                ? $askedAt->copy()->addDay()->setTime($cfg['work_from'], 0)
                : $askedAt->copy()->setTime($cfg['work_from'], 0);
        }

        return max(0, (int) $start->diffInMinutes($answeredAt, false));
    }

    private function inWorkHours(Carbon $at, array $cfg): bool
    {
        return $at->hour >= $cfg['work_from'] && $at->hour < $cfg['work_to'];
    }

    private function isStaffChat(array $conv): bool
    {
        return collect($conv['tags'] ?? [])->intersect(['курʼєр', "кур'єр", 'працівник', 'постачальник', 'не клієнт'])->isNotEmpty();
    }

    // -------------------------------------------------------------------------
    // Продажі (CRM)
    // -------------------------------------------------------------------------

    /**
     * Хто створив замовлення (журнал дій) і хто підтвердив оплату.
     *
     * @return array<string, array>
     */
    private function salesMetrics(Carbon $from, Carbon $to): array
    {
        $users = User::pluck('name', 'id')->all();
        $map   = $this->inboxNameByCrmUser();
        $out   = [];

        $nameOf = function (?int $userId) use ($users, $map): ?string {
            if (! $userId) {
                return null;
            }

            return $map[$userId] ?? ($users[$userId] ?? null);
        };

        // Створені замовлення: activity_log (spatie) — subject Order, event created.
        if (\App\Support\SchemaReady::has('activity_log')) {
            $created = DB::table('activity_log')
                ->where('subject_type', Order::class)
                ->where('event', 'created')
                ->whereBetween('created_at', [$from, $to])
                ->get(['subject_id', 'causer_id']);

            $orders = Order::whereIn('id', $created->pluck('subject_id'))->get()->keyBy('id');

            foreach ($created as $row) {
                $name = $nameOf($row->causer_id ? (int) $row->causer_id : null);

                if (! $name) {
                    continue;
                }

                $order = $orders->get($row->subject_id);
                $out[$name] ??= $this->emptySales();
                $out[$name]['orders_created']++;
                $out[$name]['orders_sum'] += (float) ($order->total_price ?? 0) - (float) ($order->discount_amount ?? 0);

                if ($order && (int) $order->duration <= 3) {
                    $out[$name]['trials']++;
                }
            }
        }

        // Підтверджені оплати.
        $payments = Transaction::where('type', 'income')
            ->whereNotNull('order_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get(['user_id', 'amount']);

        foreach ($payments as $t) {
            $name = $nameOf($t->user_id ? (int) $t->user_id : null);

            if ($name) {
                $out[$name] ??= $this->emptySales();
                $out[$name]['payments']++;
                $out[$name]['payments_sum'] += (float) $t->amount;
            }
        }

        // Продовження: замовлення, що закінчились у тиждень, і чи є наступне протягом 7 днів.
        $ended = Order::whereBetween('end_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', ['active', 'finished', 'completed'])
            ->get(['id', 'client_id', 'end_date']);

        $renewedIds = Order::whereIn('client_id', $ended->pluck('client_id'))
            ->where('start_date', '>', $from->toDateString())
            ->get(['client_id', 'start_date'])
            ->groupBy('client_id');

        $creators = \App\Support\SchemaReady::has('activity_log')
            ? DB::table('activity_log')->where('subject_type', Order::class)->where('event', 'created')
                ->whereIn('subject_id', $ended->pluck('id'))->pluck('causer_id', 'subject_id')
            : collect();

        foreach ($ended as $order) {
            $name = $nameOf($creators->get($order->id) ? (int) $creators->get($order->id) : null);

            if (! $name) {
                continue;
            }

            $renewed = ($renewedIds->get($order->client_id) ?? collect())
                ->contains(fn ($o) => Carbon::parse($o->start_date)->between(
                    Carbon::parse($order->end_date), Carbon::parse($order->end_date)->addDays(7),
                ));

            $out[$name] ??= $this->emptySales();
            $out[$name]['ended']++;
            $out[$name]['renewed'] += $renewed ? 1 : 0;
        }

        foreach ($out as &$m) {
            $m['renewal_rate'] = $m['ended'] > 0 ? round($m['renewed'] / $m['ended'] * 100) : null;
        }
        unset($m);

        return $out;
    }

    /**
     * Факапи, які видно в CRM без чатів: замовлення на завтра після дедлайну,
     * старт без оплати, заяви про оплату, що висять понад добу.
     */
    private function crmFailures(Carbon $from, Carbon $to): array
    {
        $failures = [];
        $users    = User::pluck('name', 'id')->all();
        $map      = $this->inboxNameByCrmUser();

        if (\App\Support\SchemaReady::has('activity_log')) {
            // Замовлення на завтра, створене після 11:00 — пізніше за дедлайн кухні.
            $created = DB::table('activity_log')
                ->join('orders', 'orders.id', '=', 'activity_log.subject_id')
                ->where('activity_log.subject_type', Order::class)
                ->where('activity_log.event', 'created')
                ->whereBetween('activity_log.created_at', [$from, $to])
                ->get(['orders.id', 'orders.start_date', 'activity_log.causer_id', 'activity_log.created_at']);

            foreach ($created as $row) {
                $at = Carbon::parse($row->created_at);

                if (Carbon::parse($row->start_date)->isSameDay($at->copy()->addDay()) && $at->format('H:i') > '11:00') {
                    $name = $map[(int) $row->causer_id] ?? ($users[(int) $row->causer_id] ?? '—');
                    $failures[] = [
                        'manager' => $name, 'kind' => 'late_order',
                        'text'    => "замовлення #{$row->id} на завтра створено о ".$at->format('H:i').' — після дедлайну 11:00',
                        'link'    => null, 'at' => $at->format('d.m H:i'),
                    ];
                }
            }
        }

        $unpaidStarted = Order::whereBetween('start_date', [$from->toDateString(), $to->toDateString()])
            ->where('is_paid', false)
            ->where('total_price', '>', 0)
            ->whereIn('status', ['active', 'finished', 'completed'])
            ->count();

        if ($unpaidStarted > 0) {
            $failures[] = [
                'manager' => '—', 'kind' => 'unpaid_start',
                'text'    => "{$unpaidStarted} замовлень стартували без оплати",
                'link'    => null, 'at' => null,
            ];
        }

        return $failures;
    }

    // -------------------------------------------------------------------------
    // Бали
    // -------------------------------------------------------------------------

    /**
     * 0–100: швидкість (медіана ≤ 10 хв — максимум, 60 хв — нуль), покриття
     * (частка в SLA), продажі (продовження й створені замовлення проти
     * команди), помилки (мінус за кожен факап).
     */
    public function score(array $m, array $team): int
    {
        $w = config('ops.managers.weights');

        $response = $m['median_minutes'] === null ? null
            : max(0.0, min(1.0, (60 - min(60, max(10, $m['median_minutes']))) / 50));
        $coverage = $m['within_sla'] === null ? null : $m['within_sla'] / 100;

        $salesParts = [];
        if ($m['renewal_rate'] !== null) {
            $salesParts[] = min(1.0, $m['renewal_rate'] / max(1, $team['renewal_rate'] ?: 50));
        }
        if (($team['orders_created'] ?? 0) > 0 && ($team['managers_selling'] ?? 0) > 0) {
            $salesParts[] = min(1.0, $m['orders_created'] / ($team['orders_created'] / $team['managers_selling']));
        }
        $sales = $salesParts === [] ? null : array_sum($salesParts) / count($salesParts);

        $errors = max(0.0, 1 - ($m['failures'] ?? 0) * config('ops.managers.penalty_per_error') / max(1, $w['errors']));

        // Чого немає даних — не рахуємо, а розподіляємо вагу між рештою.
        $parts = array_filter([
            'response' => $response, 'coverage' => $coverage, 'sales' => $sales, 'errors' => $errors,
        ], fn ($v) => $v !== null);

        $weight = array_sum(array_intersect_key($w, $parts)) ?: 1;
        $score  = 0.0;

        foreach ($parts as $key => $value) {
            $score += $value * $w[$key];
        }

        return (int) round($score / $weight * 100);
    }

    private function team(array $managers): array
    {
        $ended   = array_sum(array_column($managers, 'ended'));
        $renewed = array_sum(array_column($managers, 'renewed'));
        $times   = collect($managers)->pluck('median_minutes')->filter();

        return [
            'renewal_rate'     => $ended > 0 ? round($renewed / $ended * 100) : null,
            'orders_created'   => array_sum(array_column($managers, 'orders_created')),
            'managers_selling' => collect($managers)->filter(fn ($m) => ($m['orders_created'] ?? 0) > 0)->count(),
            'median_minutes'   => $times->isEmpty() ? null : round((float) $times->median(), 1),
            'unanswered'       => array_sum(array_column($managers, 'unanswered')),
        ];
    }

    private function emptyChat(): array
    {
        return [
            'chats' => 0, 'replies' => 0, 'response_minutes' => [], 'median_minutes' => null,
            'p90_minutes' => null, 'within_sla' => null, 'unanswered' => 0,
        ];
    }

    private function emptySales(): array
    {
        return [
            'orders_created' => 0, 'orders_sum' => 0.0, 'trials' => 0, 'payments' => 0, 'payments_sum' => 0.0,
            'ended' => 0, 'renewed' => 0, 'renewal_rate' => null,
        ];
    }

    /** id користувача CRM → імʼя в Inbox (OPS_MANAGER_MAP="Ірина=11,Катя=12"). */
    private function inboxNameByCrmUser(): array
    {
        $out = [];

        foreach (config('ops.managers.crm_user_map', []) as $pair) {
            if (count($pair) === 2) {
                $out[(int) $pair[1]] = $pair[0];
            }
        }

        return $out;
    }

    private function hours(int $minutes): string
    {
        return $minutes >= 120 ? round($minutes / 60).' год' : $minutes.' хв';
    }
}
