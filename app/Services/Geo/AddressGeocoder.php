<?php

namespace App\Services\Geo;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Координати адреси доставки через Nominatim (OpenStreetMap).
 *
 * Без координат точка не потрапляє в маршрути (мураха): адреса, вписана вручну
 * в поле «Адреса (можна редагувати)» або створена через Inbox API, раніше
 * зберігалась з lat/lng = null. Шукаємо в межах Києва й області.
 */
class AddressGeocoder
{
    /** Київ і найближча область (lon_min, lat_max, lon_max, lat_min). */
    private const VIEWBOX = '29.70,50.85,31.30,50.10';

    public function enabled(): bool
    {
        return (bool) config('services.geocoder.enabled', true);
    }

    /** @return array{0: float, 1: float}|null */
    public function geocode(?string $address): ?array
    {
        $address = trim((string) $address);
        if (! $this->enabled() || mb_strlen($address) < 5) {
            return null;
        }

        // «корпус 1», «кв. 5», «під'їзд 2», «ЖК …» Nominatim не розуміє — пробуємо й без них.
        $clean = trim(preg_replace([
            '/,?\s*(корпус|корп\.?|к\.)\s*\S+/ui',
            "/,?\s*(кв\.?|квартира|під['ʼ’]?їзд|поверх|офіс)\s*\S+/ui",
            '/,?\s*ЖК\s*[«"]?[^,»"]*[»"]?/ui',
        ], '', $address), " ,");

        $queries = array_values(array_unique(array_filter([$address, $clean])));
        if (! preg_match('/київ|kyiv|kiev/ui', $address)) {
            foreach ($queries as $q) {
                $queries[] = $q.', Київ';
            }
        }

        foreach ($queries as $q) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'HorenkoCRM/1.0 (delivery addresses)'])
                    ->timeout(6)
                    ->get('https://nominatim.openstreetmap.org/search', [
                        'q'            => $q,
                        'format'       => 'json',
                        'limit'        => 1,
                        'countrycodes' => 'ua',
                        'viewbox'      => self::VIEWBOX,
                        'bounded'      => 1,
                    ]);
            } catch (\Throwable $e) {
                Log::warning('Geocoder: '.$e->getMessage(), ['address' => $address]);

                return null;
            }

            $hit = $response->successful() ? ($response->json()[0] ?? null) : null;
            if ($hit && isset($hit['lat'], $hit['lon'])) {
                return [(float) $hit['lat'], (float) $hit['lon']];
            }
        }

        Log::info('Geocoder: адресу не знайдено', ['address' => $address]);

        return null;
    }
}
