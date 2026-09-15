<?php

namespace App\Filament\Resources\StockDocumentResource\Pages;

use App\Filament\Resources\StockDocumentResource;
use App\Models\Ingredient;
use App\Models\Packaging;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditStockDocument extends EditRecord
{
    protected static string $resource = StockDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('post')
                ->label('Провести')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn () => $this->record->isDraft() && StockDocumentResource::canPost())
                ->requiresConfirmation()
                ->modalHeading('Провести чернетку?')
                ->modalDescription('Збережіть правки перед проведенням. Позиції потраплять на склад, середня ціна перерахується.')
                ->action(function () {
                    $this->record->post(auth()->id());
                    \Filament\Notifications\Notification::make()->title('Документ проведено')->success()->send();
                    $this->redirect($this->getResource()::getUrl('index'));
                }),
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Відновлюємо item_category при відкритті форми редагування
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $firstItem = $this->record->items()->first();

        if ($firstItem && $firstItem->itemable_type === Packaging::class) {
            $data['item_category'] = Packaging::class;
        } else {
            $data['item_category'] = Ingredient::class;
        }

        return $data;
    }
}
