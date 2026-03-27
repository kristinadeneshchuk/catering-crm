<?php

namespace App\Observers;

use App\Jobs\RecalculateDailyMenuCosts;
use App\Models\StockDocumentItem;

class StockDocumentItemObserver
{
    // Перераховуємо собівартість меню тільки при документах надходження.
    // dispatchAfterResponse() — розрахунок запускається після того як
    // сторінка вже віддана браузеру. Користувач не чекає, сервер не грузиться.
    private function recalculateIfReceipt(StockDocumentItem $item): void
    {
        if ($item->stockDocument?->type === 'receipt') {
            RecalculateDailyMenuCosts::dispatchAfterResponse();
        }
    }

    public function created(StockDocumentItem $item): void
    {
        $this->recalculateIfReceipt($item);
    }

    public function updated(StockDocumentItem $item): void
    {
        $this->recalculateIfReceipt($item);
    }

    public function deleted(StockDocumentItem $item): void
    {
        $this->recalculateIfReceipt($item);
    }
}
