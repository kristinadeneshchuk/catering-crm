<?php

namespace App\Filament\Resources\AllergenResource\Pages;

use App\Filament\Resources\AllergenResource;
use App\Models\Ingredient;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAllergen extends EditRecord
{
    protected static string $resource = AllergenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('applyToGroup')
                ->label('Застосувати до групи')
                ->icon('heroicon-m-squares-plus')
                ->color('warning')
                ->modalHeading('Масово призначити алерген')
                ->modalDescription(null)
                ->form([
                    Select::make('group')
                        ->label('Група інгредієнтів')
                        ->options(
                            Ingredient::query()
                                ->whereNotNull('group')
                                ->distinct()
                                ->orderBy('group')
                                ->pluck('group', 'group')
                        )
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    $ingredients = Ingredient::where('group', $data['group'])->get();

                    $count = $ingredients->count();
                    foreach ($ingredients as $ingredient) {
                        $ingredient->allergens()->syncWithoutDetaching([$this->record->id]);
                    }

                    Notification::make()
                        ->title("Призначено {$count} інгредієнтам")
                        ->success()
                        ->send();

                    $this->fillForm();
                }),

            Actions\DeleteAction::make(),
        ];
    }

    // Filament handles sync automatically via ->relationship() in the form

    protected function getRedirectUrl(): string
{
    return $this->getResource()::getUrl('index');
}
}
