<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use Filament\Resources\Pages\CreateRecord;
use App\Models\Ingredient;
use App\Models\Packaging;

class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    // 🔥 Перехоплюємо дані форми ПЕРЕД збереженням у базу
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (($data['type'] ?? 'full') === 'partial') {
            $groups = $data['selected_groups'] ?? [];
            
            // Якщо увімкнули тумблер упаковки - додаємо її в масив
            if (!empty($data['include_packagings'])) {
                $groups[] = 'packagings';
            }
            $data['selected_groups'] = $groups;
        }
        
        // Видаляємо віртуальне поле, щоб база даних не лаялася
        unset($data['include_packagings']);
        
        return $data;
    }

    // 🔥 Генеруємо товари ПІСЛЯ створення документа
    protected function afterCreate(): void
    {
        /** @var \App\Models\Inventory $inventory */
        $inventory = $this->record;

        $type = $inventory->type;
        $selectedGroups = $inventory->selected_groups ?? [];

        // 1. Чи треба нам тягнути упаковку?
        $fetchPackagings = ($type === 'full') || in_array('packagings', $selectedGroups);

        // 2. Які інгредієнти нам треба тягнути?
        $ingredientsQuery = Ingredient::query();
        
        if ($type === 'partial') {
            $ingredientGroups = array_filter($selectedGroups, fn($g) => $g !== 'packagings');
            
            if (!empty($ingredientGroups)) {
                $ingredientsQuery->whereIn('group', $ingredientGroups);
            } else {
                $ingredientsQuery->whereId(0); 
            }
        }

        // 3. Записуємо інгредієнти
        $ingredients = $ingredientsQuery->get();
        foreach ($ingredients as $ing) {
            $inventory->items()->create([
                'itemable_type' => Ingredient::class,
                'itemable_id'   => $ing->id,
                'name'          => $ing->name,
                'unit'          => $ing->unit,
                'expected_qty'  => $ing->stock ?? 0, 
                'price'         => $ing->average_price ?? 0, 
            ]);
        }

        // 4. Записуємо упаковку
        if ($fetchPackagings) {
            $packagings = Packaging::all();
            foreach ($packagings as $pack) {
                $inventory->items()->create([
                    'itemable_type' => Packaging::class,
                    'itemable_id'   => $pack->id,
                    'name'          => $pack->name,
                    'unit'          => $pack->unit ?? 'шт',
                    'expected_qty'  => $pack->stock ?? 0,
                    'price'         => $pack->price ?? 0,
                ]);
            }
        }
    }

    // 🔥 ДОДАЄМО РЕДИРЕКТ ОСЬ ТУТ
    protected function getRedirectUrl(): string
    {
        // Після створення документа перекидаємо користувача на сторінку редагування (там де буде наша велика таблиця)
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}