<?php

namespace App\Jobs;

use App\Models\DailyMenu;
use App\Filament\Resources\DailyMenuResource;
use Illuminate\Foundation\Bus\Dispatchable;

class RecalculateDailyMenuCosts
{
    use Dispatchable;

    public function handle(): void
    {
        $records = DailyMenu::with([
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
            'menuItems.mealType',
        ])->get();

        foreach ($records as $record) {
            $record->updateQuietly([
                'cached_cost_950'  => DailyMenuResource::calculatePlanCost($record, 950,  [1, 3, 5]),
                'cached_cost_1500' => DailyMenuResource::calculatePlanCost($record, 1500, [1, 2, 3, 4, 5]),
                'cached_cost_2500' => DailyMenuResource::calculatePlanCost($record, 2500, [1, 2, 3, 4, 5]),
            ]);
        }
    }
}
