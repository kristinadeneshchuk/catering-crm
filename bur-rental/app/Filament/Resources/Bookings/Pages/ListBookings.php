<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    // Броні створює сайт, а не менеджер: ручне створення обійшло б перевірку
    // наявності. Телефонну бронь оформлюють через сайт від імені клієнта.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
