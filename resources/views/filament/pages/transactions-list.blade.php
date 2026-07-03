<x-filament-panels::page>
    <div class="flex flex-col gap-y-6">
        <x-filament-panels::resources.tabs />

        @include('filament.pages.daily-cash-header', ['summary' => $summary])

        {{ $this->table }}
    </div>
</x-filament-panels::page>
