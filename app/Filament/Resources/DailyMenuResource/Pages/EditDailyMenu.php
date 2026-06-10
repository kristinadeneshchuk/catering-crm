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
            Actions\Action::make('day_tech_cards')
                ->label('Техкарти дня (PDF)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(fn () => route('print.daily-menu.tech-cards', ['dailyMenuId' => $this->record->id]))
                ->openUrlInNewTab(),
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
            'cached_cost_950'  => DailyMenuResource::calculatePlanCost($record, 950),
            'cached_cost_1500' => DailyMenuResource::calculatePlanCost($record, 1500),
            'cached_cost_2500' => DailyMenuResource::calculatePlanCost($record, 2500),
        ]);
    }
}
