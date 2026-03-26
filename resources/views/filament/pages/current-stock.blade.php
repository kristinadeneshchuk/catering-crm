<x-filament-panels::page>

    {{-- ── ВКЛАДКИ ── --}}
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$activeTab === 'ingredients'"
            wire:click="$set('activeTab', 'ingredients')">
            Продукти
        </x-filament::tabs.item>
        <x-filament::tabs.item
            :active="$activeTab === 'packaging'"
            wire:click="$set('activeTab', 'packaging')">
            Упаковка та госптовари
        </x-filament::tabs.item>
    </x-filament::tabs>

    @php $stats = $this->getStats(); @endphp

    {{-- ── СТАТИСТИКА ── --}}
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">

        <div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px;background:rgba(255,255,255,0.03);">
            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Всього позицій</div>
            <div style="font-size:28px;font-weight:900;line-height:1;">{{ $stats['total'] }}</div>
        </div>

        <div style="border:1px solid rgba(74,222,128,0.25);border-radius:12px;padding:16px 20px;background:rgba(74,222,128,0.05);cursor:pointer;" wire:click="$set('stockFilter', '{{ $stockFilter === 'ok' ? 'all' : 'ok' }}')">
            <div style="font-size:11px;color:#4ade80;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Є на складі</div>
            <div style="font-size:28px;font-weight:900;line-height:1;color:#4ade80;">{{ $stats['ok'] }}</div>
        </div>

        <div style="border:1px solid rgba(248,113,113,0.25);border-radius:12px;padding:16px 20px;background:rgba(248,113,113,0.05);cursor:pointer;" wire:click="$set('stockFilter', '{{ $stockFilter === 'deficit' ? 'all' : 'deficit' }}')">
            <div style="font-size:11px;color:#f87171;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Дефіцит</div>
            <div style="font-size:28px;font-weight:900;line-height:1;color:#f87171;">{{ $stats['deficit'] }}</div>
        </div>

        <div style="border:1px solid rgba(255,255,255,0.08);border-radius:12px;padding:16px 20px;background:rgba(255,255,255,0.03);cursor:pointer;" wire:click="$set('stockFilter', '{{ $stockFilter === 'zero' ? 'all' : 'zero' }}')">
            <div style="font-size:11px;color:#6b7280;text-transform:uppercase;font-weight:600;letter-spacing:0.5px;margin-bottom:6px;">Нуль</div>
            <div style="font-size:28px;font-weight:900;line-height:1;color:#9ca3af;">{{ $stats['zero'] }}</div>
        </div>

    </div>

    {{-- ── ЗАГАЛЬНА ВАРТІСТЬ СКЛАДУ ── --}}
    <div style="border:1px solid rgba(99,102,241,0.2);border-radius:12px;padding:14px 20px;background:rgba(99,102,241,0.05);display:flex;align-items:center;gap:12px;">
        <div style="font-size:12px;color:#a5b4fc;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Загальна вартість складу (тільки позитивні залишки)</div>
        <div style="margin-left:auto;font-size:22px;font-weight:900;color:#c4b5fd;">
            {{ number_format($stats['value'], 2, '.', ' ') }} ₴
        </div>
    </div>

    {{-- ── АКТИВНИЙ ФІЛЬТР ── --}}
    @if($stockFilter !== 'all')
    <div style="display:flex;align-items:center;gap:8px;padding:8px 14px;background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.2);border-radius:8px;">
        <span style="font-size:12px;color:#a5b4fc;">
            Фільтр активний:
            <strong>{{ ['ok'=>'Є на складі','deficit'=>'Дефіцит','zero'=>'Нуль'][$stockFilter] }}</strong>
        </span>
        <button wire:click="$set('stockFilter','all')"
            style="margin-left:auto;font-size:11px;color:#a5b4fc;background:none;border:1px solid rgba(165,180,252,0.3);border-radius:6px;padding:2px 10px;cursor:pointer;">
            × Скинути
        </button>
    </div>
    @endif

    {{-- ── ТАБЛИЦЯ ── --}}
    {{ $this->table }}

</x-filament-panels::page>
