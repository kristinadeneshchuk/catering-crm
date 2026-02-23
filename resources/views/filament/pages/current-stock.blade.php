<x-filament-panels::page>
    <x-filament::tabs>
        <x-filament::tabs.item 
            :active="$activeTab === 'ingredients'" 
            wire:click="$set('activeTab', 'ingredients')"
        >
            🍏 Продукти
        </x-filament::tabs.item>

        {{-- 
        <x-filament::tabs.item 
            :active="$activeTab === 'half_dishes'" 
            wire:click="$set('activeTab', 'half_dishes')"
        >
            🥣 Напівфабрикати
        </x-filament::tabs.item>
        --}}
        
        <x-filament::tabs.item 
            :active="$activeTab === 'packaging'" 
            wire:click="$set('activeTab', 'packaging')"
        >
            📦 Упаковка та госптовари
        </x-filament::tabs.item>
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>