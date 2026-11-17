<?php

namespace App\Observers;

use App\Models\ClientAddress;
use App\Services\Geo\AddressGeocoder;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;

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

    /**
     * Координат так і немає (опечатка, незнайома вулиця) — точка не стане в маршрут.
     * Кажемо одразу: тому, хто зберігає, і в чат менеджерів (раз на той самий текст).
     */
    public function saved(ClientAddress $address): void
    {
        try {
            if (! app(AddressGeocoder::class)->enabled() || trim((string) $address->address) === '') {
                return;
            }
            if (! empty($address->lat) && ! empty($address->lng)) {
                return;
            }
            if (! $address->wasRecentlyCreated && ! $address->wasChanged(['address', 'lat', 'lng'])) {
                return;
            }

            if (auth()->check() && class_exists(\Filament\Notifications\Notification::class)) {
                \Filament\Notifications\Notification::make()
                    ->title('Адресу не знайдено на карті')
                    ->body("«{$address->address}» збережено без координат — у маршрут така точка не стане. Виберіть адресу з пошуку або поставте точку на карті.")
                    ->danger()->persistent()->send();
            }

            if (! Cache::add('addr-nocoords:'.$address->id.':'.md5((string) $address->address), 1, now()->addHours(12))) {
                return;
            }

            try {
                $name = $address->client?->name ?? 'Клієнт';
            } catch (\Throwable) {
                $name = 'Клієнт';
            }
            app(TelegramService::class)->sendToManager(implode("\n", [
                '📍 Адреса без координат — у маршрут не стане',
                "{$name} (клієнт №{$address->client_id})",
                "«{$address->address}»",
                'Картка клієнта → адреса: виберіть із пошуку або поставте точку на карті.',
            ]));
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
