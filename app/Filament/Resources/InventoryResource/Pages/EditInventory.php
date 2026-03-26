<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;

class EditInventory extends EditRecord
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('complete')
                ->label('Провести інвентаризацію')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Провести інвентаризацію?')
                ->modalDescription('Увага! Після проведення система перезапише поточні залишки на складі тими цифрами, які ви ввели в колонку "Фактичний залишок". Порожні поля будуть проігноровані. Документ стане недоступним для змін. Продовжити?')
                ->modalSubmitActionLabel('Так, провести')
                ->visible(fn () => $this->record->status === 'draft')
                ->action(function () {
                    $this->record->applyInventory();

                    Notification::make()
                        ->title('Успішно проведено!')
                        ->body('Залишки на складі оновлено.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['status', 'total_surplus', 'total_shortage']);
                    $this->redirect($this->getResource()::getUrl('edit', ['record' => $this->record]));
                }),

            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->status === 'draft'),
        ];
    }

    // Слухаємо подію від RelationManager — оновлюємо статистику миттєво
    #[On('inventory-stats-updated')]
    public function refreshInventoryStats(): void
    {
        $this->record->refresh();
        $this->refreshFormData(['total_surplus', 'total_shortage']);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    public function getInventoryStats(): array
    {
        $items    = $this->record->items;
        $total    = $items->count();
        $filled   = $items->whereNotNull('actual_qty')->count();
        $surplus  = (float)($this->record->total_surplus ?? 0);
        $shortage = (float)($this->record->total_shortage ?? 0);
        $withDiff = $items->filter(fn ($i) => $i->actual_qty !== null && round($i->actual_qty - $i->expected_qty, 3) != 0)->count();

        return compact('total', 'filled', 'surplus', 'shortage', 'withDiff');
    }

    protected function getFooterWidgets(): array { return []; }
}
