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

        $params = array_map(fn ($q) => ['q' => $q], $queries);

        // Рядок, як його вписує менеджер («м. Київ, вулиця Джона Маккейна, 26»,
        // «вул. Ніжинська буд. 7»), Nominatim часто не знаходить. Окремо вулиця
        // й будинок знаходяться: пробуємо структурованим запитом останнім.
        if ($parts = self::streetAndHouse($clean)) {
            $params[] = ['street' => $parts[1].' '.$parts[0], 'city' => $parts[2] ?? 'Київ'];
        }

        foreach ($params as $p) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'HorenkoCRM/1.0 (delivery addresses)'])
                    ->timeout(6)
                    ->get('https://nominatim.openstreetmap.org/search', $p + [
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

    /**
     * Вулиця й будинок з вписаної руками адреси: без міста, району в дужках,
     * «буд.»; скорочення типу вулиці розгорнуті. Null — будинок не знайшли.
     *
     * @return array{0: string, 1: string, 2: ?string}|null [вулиця, будинок, населений пункт поза Києвом]
     */
    public static function streetAndHouse(string $address): ?array
    {
        $s = preg_replace('/\([^)]*\)/u', ' ', $address);
        $s = preg_replace('/(^|[\s,])(м\.|місто|г\.)\s*/ui', '$1', $s);
        $s = preg_replace('/(^|,)\s*(Київ|Киев|Kyiv|Kiev|Україна|Украина)\s*(?=,|$)/ui', '$1', $s);
        $s = preg_replace('/\bбуд(инок)?\.?\s*/ui', ' ', $s);
        $s = trim(preg_replace(['/\s*,\s*(,\s*)+/u', '/\s+/u'], [', ', ' '], $s), " ,");

        $house = '(\d+[\p{L}]?(?:[\/-]\d*[\p{L}]?)?(?:\s?к\d+)?)';

        if (preg_match('/^(.*?\p{L}.*?)[\s,]+'.$house.'$/u', $s, $m)) {
            [$street, $num] = [$m[1], $m[2]];
        } elseif (preg_match('/^'.$house.'\s*,\s*(.*\p{L}.*)$/u', $s, $m)) {
            [$street, $num] = [$m[2], $m[1]];
        } else {
            return null;
        }

        // Кілька ком — вулиця останній шматок перед будинком. Перший шматок, якщо це
        // не район, — населений пункт («Софіївська Борщагівка»): шукати в ньому, а не
        // в Києві, інакше однойменна київська вулиця дала б чужу точку.
        $locality = null;
        if (str_contains($street, ',')) {
            $pieces   = array_map('trim', explode(',', $street));
            $street   = (string) array_pop($pieces);
            $locality = collect($pieces)->first(fn ($p) => $p !== '' && ! preg_match('/район|р-н|ЖК/ui', $p));
        }

        // Скорочення типу вулиці: лише з крапкою чи пробілом після, щоб не зачепити «вулиця», «бульвар».
        $street = trim(preg_replace([
            '/^вул(?:\.\s*|\s+)/ui', '/^(?:просп(?:\.\s*|\s+)|пр-т\.?\s*|пр\.\s*)/ui', '/^бул(?:\.\s*|\s+)/ui',
            '/^пров(?:\.\s*|\s+)/ui', '/^пл\.\s*/ui', '/^наб(?:\.\s*|\s+)/ui',
        ], ['вулиця ', 'проспект ', 'бульвар ', 'провулок ', 'площа ', 'набережна '], trim($street, " ,")));

        return $street !== '' ? [$street, str_replace(' ', '', $num), $locality] : null;
    }
}
