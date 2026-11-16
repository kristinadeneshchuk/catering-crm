<?php

namespace App\Console\Commands;

use App\Models\ClientAddress;
use App\Services\Geo\AddressGeocoder;
use Illuminate\Console\Command;

/**
 * Дозаповнює координати адрес без lat/lng (точки, яких не бачать маршрути).
 * Nominatim дозволяє ~1 запит/с — між адресами пауза.
 */
class GeocodeMissingAddresses extends Command
{
    protected $signature = 'addresses:geocode-missing {--active : лише клієнти з активними/новими замовленнями} {--dry : лише показати}';

    protected $description = 'Підставити координати адресам доставки без lat/lng';

    public function handle(AddressGeocoder $geocoder): int
    {
        $query = ClientAddress::query()->where(fn ($q) => $q->whereNull('lat')->orWhereNull('lng')->orWhere('lat', ''));
        if ($this->option('active')) {
            $query->whereHas('client.orders', fn ($q) => $q->whereIn('status', ['new', 'active'])->whereDate('end_date', '>=', now()->toDateString()));
        }

        $found = 0;
        $missed = [];
        foreach ($query->orderBy('id')->get() as $i => $address) {
            if ($i > 0 && ! app()->runningUnitTests()) {
                usleep(1_100_000);
            }
            $coords = $geocoder->geocode($address->address);
            if (! $coords) {
                $missed[] = "#{$address->id} (клієнт {$address->client_id}): {$address->address}";
                continue;
            }
            $found++;
            $this->line("#{$address->id} {$address->address} → {$coords[0]}, {$coords[1]}");
            if (! $this->option('dry')) {
                $address->forceFill(['lat' => $coords[0], 'lng' => $coords[1]])->saveQuietly();
            }
        }

        $this->info("Знайдено координати: {$found}".($this->option('dry') ? ' (dry, не записано)' : ''));
        foreach ($missed as $m) {
            $this->warn("Не знайдено: {$m}");
        }

        return self::SUCCESS;
    }
}
