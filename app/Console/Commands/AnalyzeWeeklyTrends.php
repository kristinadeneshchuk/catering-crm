<?php

namespace App\Console\Commands;

use App\Models\OrderCall;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Тижневий трендовий звіт: чи падають раціони і чому саме.
 *
 * Ключова декомпозиція: Раціони = Активні клієнти × Днів на клієнта.
 * Тому падіння раціонів завжди розкладається на дві причини:
 *   1) менше клієнтів (приплив не покриває відтік),
 *   2) клієнти беруть менше днів (коротші підписки / пропуски).
 * Команда рахує внесок кожної причини точно (exact decomposition), а не «на око».
 *
 * Тільки читання — жодних записів у БД.
 */
class AnalyzeWeeklyTrends extends Command
{
    protected $signature = 'analytics:weekly-trends
        {--weeks=12 : Скільки тижнів аналізувати (включно з поточним неповним)}
        {--project= : Фільтр по проєкту (напр. avocado_food)}
        {--include-current : Включати поточний неповний тиждень (за замовчуванням — ні)}
        {--csv= : Шлях, куди додатково вивантажити таблицю в CSV}';

    protected $description = 'Аналіз тижневої динаміки раціонів: клієнти, дні на клієнта, приплив/відтік, retention';

    public function handle(): int
    {
        $weeks          = max(2, (int) $this->option('weeks'));
        $project        = $this->option('project');
        $includeCurrent = (bool) $this->option('include-current');

        // Межі тижнів (пн–нд). Останній тиждень — минулий повний, якщо не --include-current.
        $lastWeekStart = Carbon::now()->startOfWeek();
        if (! $includeCurrent) {
            $lastWeekStart->subWeek();
        }
        $firstWeekStart = $lastWeekStart->copy()->subWeeks($weeks - 1);

        // Один тиждень «до» — потрібен, щоб порахувати відтік/повернення для першого тижня вибірки.
        $probeStart = $firstWeekStart->copy()->subWeek();

        $rows = $this->fetchDays($probeStart, $lastWeekStart->copy()->endOfWeek(), $project);

        if ($rows->isEmpty()) {
            $this->warn('За вказаний період немає жодного дня доставки.');
            return self::SUCCESS;
        }

        // Розкладаємо дні по тижнях: [індекс тижня => дані]
        $buckets    = [];   // weekKey => ['portions' => int, 'clients' => [id => днів], 'revenue' => float]
        $firstSeen  = [];   // client_id => найраніша дата доставки взагалі в вибірці
        foreach ($rows as $r) {
            $date = Carbon::parse($r->date);
            $key  = $date->copy()->startOfWeek()->format('Y-m-d');

            $buckets[$key]['portions']                 = ($buckets[$key]['portions'] ?? 0) + 1;
            $buckets[$key]['clients'][$r->client_id]   = ($buckets[$key]['clients'][$r->client_id] ?? 0) + 1;
            $buckets[$key]['revenue']                  = ($buckets[$key]['revenue'] ?? 0.0) + $this->dayRevenue($r);

            if (! isset($firstSeen[$r->client_id]) || $date->lt($firstSeen[$r->client_id])) {
                $firstSeen[$r->client_id] = $date;
            }
        }

        // Для «новий клієнт» треба знати, чи він взагалі колись їв раніше за вибірку.
        $everBefore = $this->clientsActiveBefore($probeStart, $project);

        $series = [];
        for ($i = 0; $i < $weeks; $i++) {
            $start = $firstWeekStart->copy()->addWeeks($i);
            $key   = $start->format('Y-m-d');
            $prev  = $start->copy()->subWeek()->format('Y-m-d');

            $curClients  = array_keys($buckets[$key]['clients']  ?? []);
            $prevClients = array_keys($buckets[$prev]['clients'] ?? []);

            $portions = $buckets[$key]['portions'] ?? 0;
            $clients  = count($curClients);

            $retained = count(array_intersect($curClients, $prevClients));
            $churned  = count(array_diff($prevClients, $curClients));

            // Новий = вперше з'явився саме цього тижня і ніколи не їв до вибірки.
            $new = 0;
            foreach (array_diff($curClients, $prevClients) as $cid) {
                $isNew = ! isset($everBefore[$cid])
                    && isset($firstSeen[$cid])
                    && $firstSeen[$cid]->betweenIncluded($start, $start->copy()->endOfWeek());
                $new += $isNew ? 1 : 0;
            }
            $returned = count(array_diff($curClients, $prevClients)) - $new;

            $series[] = [
                'start'     => $start,
                'portions'  => $portions,
                'clients'   => $clients,
                'perClient' => $clients > 0 ? $portions / $clients : 0.0,
                'revenue'   => $buckets[$key]['revenue'] ?? 0.0,
                'new'       => $new,
                'returned'  => $returned,
                'churned'   => $churned,
                'retention' => count($prevClients) > 0 ? $retained / count($prevClients) * 100 : null,
                'ids'       => $curClients,
                'prevIds'   => $prevClients,
            ];
        }

        $this->printTable($series, $project);
        $this->printDecomposition($series);
        $this->printVerdict($series);
        $this->printRefusalReasons($firstWeekStart, $lastWeekStart->copy()->endOfWeek());

        if ($path = $this->option('csv')) {
            $this->writeCsv($series, $path);
            $this->info("CSV збережено: {$path}");
        }

        return self::SUCCESS;
    }

    /**
     * Дні доставки з полями замовлення, потрібними для пропорційної виручки.
     */
    private function fetchDays(Carbon $from, Carbon $to, ?string $project)
    {
        return DB::table('order_days')
            ->join('orders', 'order_days.order_id', '=', 'orders.id')
            ->whereBetween('order_days.date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->when($project, fn ($q) => $q->where('orders.project', $project))
            ->select([
                'order_days.date',
                'order_days.discount_amount as day_discount',
                'orders.client_id',
                'orders.total_price',
                'orders.discount_amount as order_discount',
                'orders.duration',
            ])
            ->get();
    }

    /**
     * Клієнти, які мали доставки ще до початку вибірки, — щоб не рахувати їх «новими».
     * Повертає мапу [client_id => true] для дешевого isset().
     */
    private function clientsActiveBefore(Carbon $before, ?string $project): array
    {
        $ids = DB::table('order_days')
            ->join('orders', 'order_days.order_id', '=', 'orders.id')
            ->where('order_days.date', '<', $before->format('Y-m-d'))
            ->when($project, fn ($q) => $q->where('orders.project', $project))
            ->distinct()
            ->pluck('orders.client_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * Пропорційна виручка одного дня (та сама логіка, що в telegram:weekly-digest).
     */
    private function dayRevenue(object $r): float
    {
        $duration = max(1, (int) $r->duration);
        $base     = (float) $r->total_price / $duration;
        $orderD   = (float) $r->order_discount / $duration;
        $dayD     = (float) $r->day_discount;

        return max(0.0, $base - $orderD - $dayD);
    }

    private function printTable(array $series, ?string $project): void
    {
        $title = 'Тижнева динаміка раціонів' . ($project ? " — проєкт {$project}" : '');
        $this->line('');
        $this->info($title);

        $rows = [];
        foreach ($series as $i => $w) {
            $prev = $series[$i - 1] ?? null;

            $rows[] = [
                $w['start']->format('d.m') . '–' . $w['start']->copy()->endOfWeek()->format('d.m'),
                $w['portions'],
                $prev ? $this->delta($w['portions'], $prev['portions']) : '—',
                $w['clients'],
                $prev ? $this->delta($w['clients'], $prev['clients']) : '—',
                number_format($w['perClient'], 1),
                '+' . $w['new'],
                '+' . $w['returned'],
                '-' . $w['churned'],
                $w['retention'] === null ? '—' : round($w['retention']) . '%',
                number_format($w['revenue'], 0, '.', ' '),
                $w['portions'] > 0 ? number_format($w['revenue'] / $w['portions'], 0, '.', ' ') : '—',
            ];
        }

        $this->table(
            ['Тиждень', 'Раціони', 'Δ', 'Клієнти', 'Δ', 'Дн/кл', 'Нові', 'Поверн.', 'Відтік', 'Retention', 'Виручка', 'Чек/раціон'],
            $rows
        );
    }

    /**
     * Точний розклад зміни раціонів на два драйвери:
     *   ΔP = ΔC × D_prev  +  C_now × ΔD
     * (перший доданок — «менше людей», другий — «менше днів на людину»).
     */
    private function printDecomposition(array $series): void
    {
        $last  = end($series);
        $first = $series[0];

        $this->line('');
        $this->info('Чому змінилась кількість раціонів (перший тиждень → останній)');

        $dC = $last['clients'] - $first['clients'];
        $dD = $last['perClient'] - $first['perClient'];

        [$effClients, $effDays] = $this->effects($series);
        $total = $last['portions'] - $first['portions'];

        $this->table(['Драйвер', 'Зміна', 'Вплив на раціони', 'Частка'], [
            [
                'Кількість клієнтів',
                sprintf('%+d кл. (%d → %d)', $dC, $first['clients'], $last['clients']),
                sprintf('%+.0f раціонів', $effClients),
                $total != 0 ? round(abs($effClients) / max(0.01, abs($effClients) + abs($effDays)) * 100) . '%' : '—',
            ],
            [
                'Днів на клієнта',
                sprintf('%+.1f дн. (%.1f → %.1f)', $dD, $first['perClient'], $last['perClient']),
                sprintf('%+.0f раціонів', $effDays),
                $total != 0 ? round(abs($effDays) / max(0.01, abs($effClients) + abs($effDays)) * 100) . '%' : '—',
            ],
            ['РАЗОМ', '', sprintf('%+d раціонів', $total), '100%'],
        ]);
    }

    /**
     * Внесок кожного драйвера у зміну раціонів між першим і останнім тижнем:
     *   ΔP = ΔC × D_first + C_last × ΔD  (сума точно дорівнює ΔP).
     *
     * @return array{0: float, 1: float} [вплив кількості клієнтів, вплив днів на клієнта]
     */
    private function effects(array $series): array
    {
        $first = $series[0];
        $last  = end($series);

        return [
            ($last['clients'] - $first['clients']) * $first['perClient'],
            $last['clients'] * ($last['perClient'] - $first['perClient']),
        ];
    }

    private function printVerdict(array $series): void
    {
        $last  = end($series);
        $first = $series[0];

        $totalNew     = array_sum(array_column($series, 'new'));
        $totalChurned = array_sum(array_column($series, 'churned'));
        $retentions   = array_filter(array_column($series, 'retention'), fn ($v) => $v !== null);
        $avgRetention = $retentions ? array_sum($retentions) / count($retentions) : null;

        $this->line('');
        $this->info('Висновок');

        $dP = $last['portions'] - $first['portions'];
        $this->line(sprintf(
            '  Раціони: %d → %d (%+d, %+.0f%%)',
            $first['portions'],
            $last['portions'],
            $dP,
            $first['portions'] > 0 ? $dP / $first['portions'] * 100 : 0
        ));

        $this->line(sprintf('  Приплив за період: +%d нових клієнтів', $totalNew));
        $this->line(sprintf('  Відтік за період:  -%d клієнтів (тиждень до тижня)', $totalChurned));
        if ($avgRetention !== null) {
            $this->line(sprintf('  Середній W/W retention: %.0f%%', $avgRetention));
        }

        // Головна причина — з точної декомпозиції, а не з «на око».
        [$effClients, $effDays] = $this->effects($series);
        $dC = $last['clients'] - $first['clients'];

        $this->line('');
        if ($dP < 0) {
            if (abs($effClients) > abs($effDays)) {
                $this->line('  → Головна причина падіння — КІЛЬКІСТЬ КЛІЄНТІВ: база звузилась на ' . abs($dC) . ' кл.');
                $this->line($totalChurned > $totalNew
                    ? '    Воронка не покриває відтік: відпало більше, ніж прийшло нових.'
                    : '    Нові приходять, але не затримуються — дивись W/W retention по тижнях.');
            } else {
                $this->line('  → Головна причина падіння — ГЛИБИНА ЧЕКА: клієнти беруть менше днів ('
                    . sprintf('%.1f → %.1f', $first['perClient'], $last['perClient']) . ').');
                $this->line('    Трафік тут ні до чого — питання в тривалості підписки та пропусках днів.');
            }
        } elseif ($dP > 0) {
            $this->line('  → Раціони зростають; основний драйвер — '
                . (abs($effClients) > abs($effDays) ? 'кількість клієнтів.' : 'глибина чека (більше днів на клієнта).'));
        }

        // Хто відпав останнього тижня — конкретні імена для обдзвону.
        $lost = array_diff($last['prevIds'], $last['ids']);
        if ($lost) {
            $names = DB::table('clients')->whereIn('id', $lost)->pluck('name', 'id');
            $this->line('');
            $this->info('Відпали на останньому тижні (' . count($lost) . ') — кандидати на обдзвін:');
            foreach ($names as $id => $name) {
                $this->line("  #{$id} {$name}");
            }
        }
    }

    private function printRefusalReasons(Carbon $from, Carbon $to): void
    {
        $reasons = OrderCall::whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])
            ->where('status', 'refused')
            ->whereNotNull('refusal_reason')
            ->where('refusal_reason', '!=', '')
            ->select('refusal_reason', DB::raw('COUNT(*) as cnt'))
            ->groupBy('refusal_reason')
            ->orderByDesc('cnt')
            ->get();

        if ($reasons->isEmpty()) {
            return;
        }

        $this->line('');
        $this->info('Причини відмов за період');
        foreach ($reasons as $r) {
            $this->line('  ' . OrderCall::refusalReasonLabel($r->refusal_reason) . " — {$r->cnt}");
        }
    }

    private function delta(float $now, float $prev): string
    {
        $diff = $now - $prev;
        $pct  = $prev > 0 ? $diff / $prev * 100 : 0;

        return sprintf('%+.0f (%+.0f%%)', $diff, $pct);
    }

    private function writeCsv(array $series, string $path): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['week_start', 'portions', 'clients', 'days_per_client', 'new', 'returned', 'churned', 'retention_pct', 'revenue', 'revenue_per_portion'], ',', '"', '\\');

        foreach ($series as $w) {
            fputcsv($fh, [
                $w['start']->format('Y-m-d'),
                $w['portions'],
                $w['clients'],
                round($w['perClient'], 2),
                $w['new'],
                $w['returned'],
                $w['churned'],
                $w['retention'] === null ? '' : round($w['retention'], 1),
                round($w['revenue'], 2),
                $w['portions'] > 0 ? round($w['revenue'] / $w['portions'], 2) : '',
            ], ',', '"', '\\');
        }

        fclose($fh);
    }
}
