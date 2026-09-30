<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            GeoSeeder::class,
            CatalogSeeder::class,
            AvailabilitySeeder::class,
            ContentSeeder::class,
            StaffSeeder::class,
        ]);

        // Демо-броні — вигадані люди з реальними на вигляд телефонами, які ще й
        // займають техніку в календарі. На бойовому сайті їм не місце.
        if (! app()->isProduction()) {
            $this->call(BookingSeeder::class);
        }
    }
}
