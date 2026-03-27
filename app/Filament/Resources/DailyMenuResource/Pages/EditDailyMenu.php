<?php

namespace App\Filament\Resources\DailyMenuResource\Pages;

use App\Filament\Resources\DailyMenuResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDailyMenu extends EditRecord
{
    protected static string $resource = DailyMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterSave(): void
    {
        $this->recalculateCachedCosts();
    }

    public function recalculateCachedCosts(): void
    {
        $record = $this->getRecord()->fresh([
            'menuItems.dish.dishIngredients.ingredient',
            'menuItems.dish.dishIngredients.childDish.dishIngredients.ingredient',
            'menuItems.mealType',
        ]);

        $record->updateQuietly([
            'cached_cost_950'  => DailyMenuResource::calculatePlanCost($record, 950,  [1, 3, 5]),
            'cached_cost_1500' => DailyMenuResource::calculatePlanCost($record, 1500, [1, 2, 3, 4, 5]),
            'cached_cost_2500' => DailyMenuResource::calculatePlanCost($record, 2500, [1, 2, 3, 4, 5]),
        ]);
    }
}
