@php
    $lat       = $currentLat ?? null;
    $lng       = $currentLng ?? null;
    $hasCoords = $lat && $lng;
    $centerLat = $hasCoords ? (float) $lat : 50.4501;
    $centerLng = $hasCoords ? (float) $lng : 30.5234;
    $zoom      = $hasCoords ? 15 : 12;

    $cfg = json_encode([
        'center'    => [$centerLat, $centerLng],
        'zoom'      => $zoom,
        'hasCoords' => (bool) $hasCoords,
        'latPath'   => 'mountedTableActionsData.0.lat',
        'lngPath'   => 'mountedTableActionsData.0.lng',
        'addrPath'  => 'mountedTableActionsData.0.address',
    ]);
@endphp

<div
    data-leaflet-cfg="{{ $cfg }}"
    style="height: 280px; width: 100%; border-radius: 8px; overflow: hidden;"
    wire:ignore
></div>

<div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
    Клікніть на карту або перетягніть маркер щоб вказати точне місце доставки
</div>
