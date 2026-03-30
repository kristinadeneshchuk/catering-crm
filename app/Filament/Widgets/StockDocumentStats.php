<?php

namespace App\Filament\Widgets;

use App\Models\StockDocument;
use Filament\Widgets\Widget;

class StockDocumentStats extends Widget
{
    protected static bool $isLazy = false;
    protected static string $view = 'filament.widgets.stock-document-stats';
    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $total       = StockDocument::sum('total_sum');
        $paid        = StockDocument::where('is_paid', true)->sum('total_sum');
        $unpaid      = StockDocument::where('is_paid', false)->sum('total_sum');
        $countAll    = StockDocument::count();
        $countPaid   = StockDocument::where('is_paid', true)->count();
        $countUnpaid = StockDocument::where('is_paid', false)->count();

        return [
            'total'         => $total,
            'paid'          => $paid,
            'unpaid'        => $unpaid,
            'countAll'      => $countAll,
            'countPaid'     => $countPaid,
            'countUnpaid'   => $countUnpaid,
            'paidPercent'   => $total > 0 ? round(($paid / $total) * 100) : 0,
            'unpaidPercent' => $total > 0 ? round(($unpaid / $total) * 100) : 0,
        ];
    }
}
