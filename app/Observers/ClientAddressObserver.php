<?php

namespace App\Observers;

use App\Models\ClientAddress;
use App\Services\Geo\AddressGeocoder;

/**
 * Адреса без координат не потрапляє в маршрути. Підставляємо lat/lng, коли
 * адресу вписали вручну (без вибору з пошуку чи маркера на карті) або коли
 * текст адреси змінили, а координати лишились від старої.
 */
class ClientAddressObserver
{
    public function saving(ClientAddress $address): void
    {
        $coordsSetNow = $address->isDirty('lat') && $address->lat !== null && $address->lat !== '';
        $textChanged  = $address->exists && $address->isDirty('address');
        $missing      = $address->lat === null || $address->lat === '' || $address->lng === null || $address->lng === '';

        if ($coordsSetNow || (! $missing && ! $textChanged)) {
            return;
        }

        $coords = app(AddressGeocoder::class)->geocode($address->address);
        if ($coords) {
            [$address->lat, $address->lng] = $coords;
        } elseif ($textChanged) {
            // Старі координати вказують на іншу адресу — краще порожньо, ніж хибна точка.
            $address->lat = null;
            $address->lng = null;
        }
    }
}
