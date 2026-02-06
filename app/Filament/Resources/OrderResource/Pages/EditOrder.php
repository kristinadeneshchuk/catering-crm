<?php

namespace App\Filament\Resources\OrderResource\Pages;

use App\Filament\Resources\OrderResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

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

    // === ДОБАВЛЕНО: Включаем вкладки сверху ===

    // 1. Объединяем форму и "связи" (транзакции) в единые вкладки
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    // 2. Название первой вкладки, где находится сама форма заказа
    public function getContentTabLabel(): ?string
    {
        return 'Інформація про замовлення';
    }
}