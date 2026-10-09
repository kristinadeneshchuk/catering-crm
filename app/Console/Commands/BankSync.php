<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Services\Bank\MonobankSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Нічний добір виписок monobank (на випадок пропущених операцій).
 *
 *   bank:sync                    — усі рахунки з токеном, 31 доба
 *   bank:sync --account=3 --days=7
 */
class BankSync extends Command
{
    protected $signature = 'bank:sync {--account= : id рахунку} {--days=31 : За скільки діб (до 31)}';

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
                $r = $sync->sync($account, (int) $this->option('days'));
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
