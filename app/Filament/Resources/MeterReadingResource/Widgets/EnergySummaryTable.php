<?php

namespace App\Filament\Resources\MeterReadingResource\Widgets;

use App\Services\EnergySummary;
use Filament\Widgets\Widget;

/** Зведення енергії по місяцях: мережа, генератор, разом і на раціон. */
class EnergySummaryTable extends Widget
{
    protected static string $view = 'filament.widgets.energy-summary-table';
    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        return ['months' => EnergySummary::lastMonths(6)];
    }
}
