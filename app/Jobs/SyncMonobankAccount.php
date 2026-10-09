<?php

namespace App\Jobs;

use App\Models\Account;
use App\Services\Bank\MonobankSync;
use Carbon\Carbon;
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

    /** $from/$to (Y-m-d) — довільний період; без них — останні $days діб. */
    public function __construct(
        public int $accountId,
        public int $days = MonobankSync::MAX_DAYS,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public function handle(MonobankSync $sync): void
    {
        $account = Account::withBankOnly()->find($this->accountId);
        if (!$account) return;

        $result = $this->from
            ? $sync->syncRange($account, Carbon::parse($this->from)->startOfDay(), Carbon::parse($this->to ?? 'now')->endOfDay())
            : $sync->sync($account, $this->days);
        Log::info('monobank: виписку підтягнуто', ['account_id' => $account->id] + $result);
    }
}
