<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Bank\MonobankSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/** Кнопка «Підтягнути зараз» — у черзі, бо банк дозволяє 1 запит на хвилину. */
class SyncMonobankAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $accountId, public int $days = MonobankSync::MAX_DAYS) {}

    public function handle(MonobankSync $sync): void
    {
        $account = Account::find($this->accountId);
        if (!$account) return;

        $result = $sync->sync($account, $this->days);
        Log::info('monobank: виписку підтягнуто', ['account_id' => $account->id] + $result);
    }
}
