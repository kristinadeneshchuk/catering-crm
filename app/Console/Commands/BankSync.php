<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Bank\MonobankSync;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Нічний добір виписок monobank (на випадок пропущених операцій).
 *
 *   bank:sync                    — усі рахунки з токеном, 31 доба
 *   bank:sync --account=3 --days=7
 *   bank:sync --account=3 --from=2026-08-20 --to=2026-09-08
 */
class BankSync extends Command
{
    protected $signature = 'bank:sync {--account= : id рахунку} {--days=31 : За скільки останніх діб} {--from= : Початок періоду Y-m-d} {--to= : Кінець періоду Y-m-d (за замовч. сьогодні)}';

    protected $description = 'Підтягнути виписки monobank у bank_operations';

    public function handle(MonobankSync $sync): int
    {
        $accounts = Account::query()
            ->whereNotNull('mono_token')
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $failed = 0;
        foreach ($accounts as $i => $account) {
            try {
                $r = $this->option('from')
                    ? $sync->syncRange($account, Carbon::parse($this->option('from'))->startOfDay(), Carbon::parse($this->option('to') ?? 'now')->endOfDay())
                    : $sync->sync($account, (int) $this->option('days'));
                $this->info("{$account->name}: отримано {$r['fetched']}, нових {$r['inserted']}");
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error("{$account->name}: {$e->getMessage()}");
            }
        }

        if ($accounts->isEmpty()) $this->line('Рахунків з токеном monobank немає.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
