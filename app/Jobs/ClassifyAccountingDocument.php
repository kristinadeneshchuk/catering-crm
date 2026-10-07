<?php

namespace App\Jobs;

use App\Models\AccountingItem;
use App\Services\Accounting\AccountingDesk;
use App\Services\Ai\AccountingClassifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Документ із «Бухгалтерії»: що це (накладна / оплата / інше) і що пропонувати. */
class ClassifyAccountingDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(private int $itemId)
    {
    }

    public function handle(AccountingClassifier $classifier, AccountingDesk $desk): void
    {
        $item = AccountingItem::find($this->itemId);

        if (! $item || $item->status !== 'new') {
            return;
        }

        $desk->route($item, $classifier->read($item));
    }
}
