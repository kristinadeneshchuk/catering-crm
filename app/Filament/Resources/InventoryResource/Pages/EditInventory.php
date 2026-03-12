<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditInventory extends EditRecord
{
    protected static string $resource = InventoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Кнопка проведення
            Actions\Action::make('complete')
                ->label('Провести інвентаризацію')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Провести інвентаризацію?')
                ->modalDescription('Увага! Після проведення система перезапише поточні залишки на складі тими цифрами, які ви ввели в колонку "Фактичний залишок". Порожні поля будуть проігноровані. Документ стане недоступним для змін. Продовжити?')
                ->modalSubmitActionLabel('Так, провести')
                ->visible(fn () => $this->record->status === 'draft') // Показуємо тільки для чернеток
                ->action(function () {
                    $this->record->applyInventory();
                    
                    Notification::make()
                        ->title('Успішно проведено!')
                        ->body('Залишки на складі оновлено.')
                        ->success()
                        ->send();

                    // Оновлюємо сторінку
                    $this->refreshFormData(['status']);
                }),

            // Кнопка видалення (якщо передумали)
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->status === 'draft'),
        ];
    }
    
    // Щоб не можна було міняти дату/категорії після проведення
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $data;
    }
}