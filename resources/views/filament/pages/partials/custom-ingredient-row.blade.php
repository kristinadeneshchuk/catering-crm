@php
    $hasConflict = isset($comp['conflict']) && !empty($comp['conflict']);
    $isResolved = $hasConflict && $comp['conflict']['is_resolved'];
    
    // Базові стилі
    $containerStyle = 'display: flex; justify-content: space-between; align-items: center; padding: 6px 10px; border-radius: 6px; margin-bottom: 4px;';
    
    if ($isResolved) {
        $containerStyle .= ' background-color: #dcfce7; border: 1px solid #86efac;'; // Зелений (Вирішено)
    } elseif ($hasConflict) {
        $containerStyle .= ' background-color: #fee2e2; border: 1px solid #fca5a5;'; // Червоний (Конфлікт)
    } else {
        $containerStyle .= ' background-color: transparent; border-bottom: 1px dashed #cbd5e1;'; // Стандарт
    }
@endphp

<div style="{{ $containerStyle }}">
    <div style="flex: 1;">
        @if($isResolved)
            {{-- ВАРІАНТ 1: ВИРІШЕНО (БУЛА ЗАМІНА) --}}
            <div style="display: flex; flex-direction: column;">
                <span style="font-size: 11px; text-decoration: line-through; color: #94a3b8;">{{ $comp['name'] }}</span>
                <div style="font-weight: 800; color: #166534; font-size: 13px; display: flex; align-items: center; gap: 5px;">
                    <x-filament::icon icon="heroicon-m-arrow-right-circle" class="w-4 h-4" style="color: #166534;" />
                    {{ $comp['conflict']['replacement']['name'] }}
                </div>
                {{-- БРУТТО ЗЛІВА --}}
                <div style="font-size: 11px; color: #15803d; font-weight: 600; margin-top: 2px;">
                    Б: {{ $comp['conflict']['replacement']['brutto'] }} | Н: {{ $comp['conflict']['replacement']['netto'] }} {{ $comp['conflict']['replacement']['unit'] }}
                </div>
                @if($comp['conflict']['comment'])
                    <div style="font-size: 10px; color: #166534; font-style: italic;">({{ $comp['conflict']['comment'] }})</div>
                @endif
            </div>

        @elseif($hasConflict)
            {{-- ВАРІАНТ 2: КОНФЛІКТ (НЕ ЇСТЬ) --}}
            <div style="display: flex; align-items: center; gap: 5px;">
                <x-filament::icon icon="heroicon-m-no-symbol" class="w-4 h-4" style="color: #b91c1c;" />
                <span style="color: #b91c1c; font-weight: 800; font-size: 13px;">{{ $comp['name'] }}</span>
            </div>
            <div style="font-size: 10px; font-weight: 900; color: #ef4444; text-transform: uppercase; margin-top: 2px;">⛔️ НЕ ЇСТЬ! (Зробіть заміну)</div>

        @else
            {{-- ВАРІАНТ 3: НОРМА (СТАНДАРТНИЙ ІНГРЕДІЄНТ) --}}
            <div style="font-weight: 600; color: #334155; font-size: 13px;">{{ $comp['name'] }}</div>
            {{-- БРУТТО ЗЛІВА --}}
            <div style="font-size: 11px; font-weight: 800; color: #64748b;">
                Б: {{ $comp['weight_brutto'] ?? $comp['weight_netto'] }} | Н: {{ $comp['weight_netto'] }}
            </div>
        @endif
    </div>

    {{-- Кнопка заміни інгредієнта (ТІЛЬКИ ЯКЩО Є ПРОБЛЕМА) --}}
    @if(($hasConflict || $isResolved) && isset($order_id) && isset($dish_id))
        <div style="margin-left: 10px; opacity: 0.8; cursor: pointer;">
            {{ ($this->replaceIngredientAction)(['order_id' => $order_id, 'dish_id' => $dish_id, 'product_id' => $comp['product_id']]) }}
        </div>
    @endif
</div>