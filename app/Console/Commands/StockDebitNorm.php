<?php

namespace App\Console\Commands;

use App\Models\Ingredient;
use App\Models\Packaging;
use App\Services\Kitchen\KitchenStockDebit;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Списання зі складу за нормою по датах готування.
 *
 *   stock:debit-norm                      — вчорашня зміна (крон о 06:00)
 *   stock:debit-norm --date=2026-09-14
 *   stock:debit-norm --from=2026-06-01 --to=2026-09-14 --dry-run --csv=/tmp/lines.csv
 *
 * Уже списані дні їжі пропускає, тож повторний запуск безпечний.
 */
class StockDebitNorm extends Command
{
    protected $signature = 'stock:debit-norm
        {--date= : Дата готування (за замовчуванням — вчора)}
        {--from= : Початок діапазону дат готування}
        {--to= : Кінець діапазону дат готування}
        {--dry-run : Лише розрахунок, нічого не записує}
        {--csv= : Шлях для CSV з рядками по продуктах}';

    protected $description = 'Списання продуктів і упаковки зі складу за нормою техкарт (п\'ятниця = сб+нд)';

    public function handle(KitchenStockDebit $debit): int
    {
        $dates = $this->cookDates();
        $dry = (bool) $this->option('dry-run');

        Ingredient::preloadAveragePrices();

        $negativeBefore = $this->negativeKeys();
        $rows = [];
        $allLines = [];
        $skipped = [];
        $failed = 0;
        $totals = ['ing' => 0.0, 'pack' => 0.0];
        $projected = [];

        foreach ($dates as $cook) {
            $pending = $debit->pendingFoodDates($cook);
            if (empty($pending)) continue;

            try {
                $plan = $debit->plan($cook, $pending);
            } catch (Throwable $e) {
                $failed++;
                $this->reportFailure($cook, $e->getMessage());
                continue;
            }

            $orders = array_sum(array_column($plan['days'], 'orders'));
            $issues = [];
            foreach ($plan['days'] as $food => $d) {
                foreach ($d['missing'] as $m) {
                    $issues[] = Carbon::parse($food)->format('d.m') . ": немає меню «{$m['plan']}» д.{$m['day_number']} ({$m['orders']} зам.)";
                }
            }
            if ($plan['skipped']) {
                $issues[] = count($plan['skipped']) . ' поз. без перерахунку';
            }

            $status = $dry ? 'dry-run' : 'проведено';

            if ($plan['blocking']) {
                $failed++;
                $status = 'НЕ проведено';
                $this->reportFailure($cook, implode('; ', $issues));
            } elseif (!$dry) {
                try {
                    $docs = $debit->post($plan);
                    Log::info('stock:debit-norm проведено', [
                        'cook_date' => $plan['cook_date'],
                        'food_dates' => $plan['food_dates'],
                        'documents' => array_map(fn ($d) => $d->id, $docs),
                        'total' => $plan['total'],
                    ]);
                    if (empty($docs)) $status = 'без замовлень';
                } catch (Throwable $e) {
                    $failed++;
                    $status = 'ПОМИЛКА';
                    $this->reportFailure($cook, $e->getMessage());
                }
            }

            if (!$plan['blocking']) {
                $totals['ing'] += $plan['ingredients_sum'];
                $totals['pack'] += $plan['packaging_sum'];
                foreach ($plan['lines'] as $l) {
                    $key = $l['kind'] . '#' . $l['id'];
                    $projected[$key] ??= ['name' => $l['name'], 'unit' => $l['unit'], 'stock' => $l['stock_before'], 'qty' => 0.0];
                    $projected[$key]['qty'] += $l['qty'];
                    $allLines[] = ['cook_date' => $plan['cook_date'], 'food_dates' => implode(' ', $plan['food_dates'])] + $l;
                }
            }
            foreach ($plan['skipped'] as $s) {
                $skipped[$s['name']] = ($skipped[$s['name']] ?? ['reason' => $s['reason'], 'grams' => 0.0]);
                $skipped[$s['name']]['grams'] += $s['grams'];
            }

            $rows[] = [
                Carbon::parse($plan['cook_date'])->translatedFormat('D d.m'),
                implode(', ', array_map(fn ($f) => Carbon::parse($f)->format('d.m'), $plan['food_dates'])),
                $orders,
                count($plan['lines']),
                number_format($plan['ingredients_sum'], 0, '.', ' '),
                number_format($plan['packaging_sum'], 0, '.', ' '),
                number_format($plan['total'], 0, '.', ' '),
                $status,
                implode('; ', $issues),
            ];
        }

        if (empty($rows)) {
            $this->info('Нема чого списувати: усі дні в діапазоні вже закриті.');
            return self::SUCCESS;
        }

        $this->table(['Готування', 'Їжа на', 'Замовл.', 'Позицій', 'Продукти ₴', 'Упаковка ₴', 'Разом ₴', 'Статус', 'Проблеми'], $rows);
        $this->line(sprintf(
            'Разом: продукти %s ₴, упаковка %s ₴, усього %s ₴ за %d дн.',
            number_format($totals['ing'], 0, '.', ' '),
            number_format($totals['pack'], 0, '.', ' '),
            number_format($totals['ing'] + $totals['pack'], 0, '.', ' '),
            count($rows),
        ));

        if ($skipped) {
            $this->newLine();
            $this->warn('Не списано (немає перерахунку в одиниці складу):');
            $this->table(['Продукт', 'Брутто, г', 'Причина'], collect($skipped)->map(fn ($s, $n) => [$n, round($s['grams']), $s['reason']])->values()->all());
        }

        $this->printNegatives($dry, $projected, $negativeBefore);

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $allLines);
            $this->line("CSV: {$path}");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Carbon[] */
    private function cookDates(): array
    {
        if ($this->option('from') || $this->option('to')) {
            $from = Carbon::parse($this->option('from') ?: $this->option('to'));
            $to = Carbon::parse($this->option('to') ?: $this->option('from'));

            return array_map(fn ($d) => Carbon::parse($d->format('Y-m-d')), iterator_to_array(CarbonPeriod::create($from, $to)));
        }

        return [Carbon::parse($this->option('date') ?: now()->subDay()->format('Y-m-d'))];
    }

    private function reportFailure(Carbon $cook, string $message): void
    {
        Log::error('stock:debit-norm не вдалось', ['cook_date' => $cook->format('Y-m-d'), 'error' => $message]);
        $this->error("{$cook->format('d.m.Y')}: {$message}");
    }

    /** @return array<string, true> */
    private function negativeKeys(): array
    {
        $keys = [];
        foreach (Ingredient::where('stock', '<', 0)->pluck('id') as $id) $keys['ingredient#' . $id] = true;
        foreach (Packaging::where('stock', '<', 0)->pluck('id') as $id) $keys['packaging#' . $id] = true;

        return $keys;
    }

    private function printNegatives(bool $dry, array $projected, array $negativeBefore): void
    {
        $rows = [];

        if ($dry) {
            foreach ($projected as $key => $p) {
                $after = $p['stock'] - $p['qty'];
                if ($after < 0) {
                    $rows[] = [$p['name'], $p['unit'], round($p['stock'], 3), round($p['qty'], 3), round($after, 3), isset($negativeBefore[$key]) ? 'так' : ''];
                }
            }
        } else {
            $items = Ingredient::where('stock', '<', 0)->get()->map(fn ($i) => ['ingredient#' . $i->id, $i])
                ->concat(Packaging::where('stock', '<', 0)->get()->map(fn ($p) => ['packaging#' . $p->id, $p]));
            foreach ($items as [$key, $m]) {
                $rows[] = [$m->name, $m->unit, '', round($projected[$key]['qty'] ?? 0, 3), round((float) $m->stock, 3), isset($negativeBefore[$key]) ? 'так' : ''];
            }
        }

        if (!$rows) {
            $this->info('Мінусових залишків ' . ($dry ? 'після списання не буде.' : 'немає.'));
            return;
        }

        usort($rows, fn ($a, $b) => $a[4] <=> $b[4]);
        $this->newLine();
        $this->warn(($dry ? 'Після списання піде в мінус' : 'У мінусі після списання') . ' (' . count($rows) . '):');
        $this->table(['Позиція', 'Од.', 'Залишок до', 'Списується', 'Залишок після', 'Був у мінусі до'], $rows);
    }

    private function writeCsv(string $path, array $lines): void
    {
        $fh = fopen($path, 'w');
        fputcsv($fh, ['cook_date', 'food_dates', 'kind', 'id', 'name', 'unit', 'grams', 'qty', 'price', 'price_source', 'sum', 'note', 'stock_before']);
        foreach ($lines as $l) {
            fputcsv($fh, [$l['cook_date'], $l['food_dates'], $l['kind'], $l['id'], $l['name'], $l['unit'], $l['grams'], $l['qty'], $l['price'], $l['price_source'], $l['sum'], $l['note'], $l['stock_before']]);
        }
        fclose($fh);
    }
}
