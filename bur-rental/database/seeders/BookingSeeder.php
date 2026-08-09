<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Lead;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Services\RentalPricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Демо-броні під адмінку: інакше інфопанель порожня і незрозуміло, що вона вміє.
 * Дати рахуються від «сьогодні», тому сид не протухає.
 */
class BookingSeeder extends Seeder
{
    public function run(RentalPricing $pricing): void
    {
        $today = Carbon::today();
        $poznyaky = Branch::where('slug', 'poznyaky')->firstOrFail();
        $petrivka = Branch::where('slug', 'petrivka')->firstOrFail();

        $rows = [
            // видача сьогодні — ще не підтверджена
            ['new', 'person', 'Олег Мартиненко', '+380 67 111 22 33', $poznyaky, 'bosch-gbh-2-26-dre', 0, 4],
            // видача сьогодні, підтверджена
            ['confirmed', 'person', 'Світлана Кравець', '+380 63 222 33 44', $poznyaky, 'makita-sg1251j', 0, 2],
            // повернення сьогодні
            ['issued', 'company', 'ТОВ «Моноліт-Буд»', '+380 50 333 44 55', $poznyaky, 'makita-hm1203c', -6, 0],
            // прострочена — має світитися червоним
            ['issued', 'person', 'Ігор Пилипенко', '+380 97 444 55 66', $petrivka, 'honda-eu22i', -9, -2],
            // майбутня бронь
            ['confirmed', 'person', 'Ганна Левченко', '+380 66 555 66 77', $petrivka, 'karcher-wd-6', 3, 6],
            // закрита минулого тижня
            ['closed', 'person', 'Дмитро Осадчий', '+380 68 666 77 88', $poznyaky, 'bosch-gll-3-80', -12, -8],
        ];

        foreach ($rows as $i => [$status, $type, $who, $phone, $branch, $slug, $fromOffset, $toOffset]) {
            $product = Product::with('tiers')->where('slug', $slug)->firstOrFail();

            $from = $today->copy()->addDays($fromOffset);
            $to = $today->copy()->addDays($toOffset);
            $days = $pricing->days($from->toDateString(), $to->toDateString());

            $booking = Booking::create([
                'number' => sprintf('BUR-%s-%06d', $today->format('y'), $i + 1),
                'branch_id' => $branch->id,
                'status' => $status,
                'client_type' => $type,
                'name' => $type === 'person' ? $who : null,
                'company' => $type === 'company' ? $who : null,
                'edrpou' => $type === 'company' ? '43215678' : null,
                'email' => $type === 'company' ? 'buh@monolit.example' : null,
                'phone' => $phone,
                'fulfilment' => $i % 3 === 0 ? 'delivery' : 'self',
                'delivery_zone_id' => $i % 3 === 0 ? $branch->city->deliveryZones->first()?->id : null,
                'address' => $i % 3 === 0 ? 'вул. Ревуцького 12, під\'їзд 3' : null,
                'payment' => $type === 'company' ? 'invoice' : 'card',
                'deposit_way' => $type === 'company' ? 'none' : 'card-hold',
                'date_from' => $from,
                'date_to' => $to,
                'rent_total' => $pricing->rentTotal($product, $days),
                'deposit_total' => $type === 'company' ? 0 : $product->deposit,
                'delivery_total' => $i % 3 === 0 ? 250 : 0,
            ]);

            $booking->items()->create([
                'product_id' => $product->id,
                'title' => $product->name,
                'qty' => 1,
                'days' => $days,
                'price_per_day' => $pricing->pricePerDay($product, $days),
                'total' => $pricing->rentTotal($product, $days),
                'deposit' => $booking->deposit_total,
            ]);

            // Закрита і скасована броні календар не тримають.
            if (in_array($status, ['closed', 'cancelled'], true)) {
                continue;
            }

            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                UnavailableDate::firstOrCreate([
                    'product_id' => $product->id,
                    'branch_id' => $branch->id,
                    'date' => $day->toDateString(),
                ], ['reason' => 'rented']);
            }
        }

        $leads = [
            ['callback', 'Андрій', '+380 67 777 88 99', 'instrument/bosch-gbh-2-26-dre', null, 'new'],
            ['b2b', 'Наталя, ТОВ «Спецбуд»', '+380 50 888 99 00', 'b2b', 'Потрібні 4 перфоратори на місяць на об\'єкт у Дарниці.', 'new'],
            ['contact', 'Максим', '+380 63 999 00 11', 'contacts', 'Чи можна забрати в неділю о 18:00?', 'in_progress'],
        ];

        foreach ($leads as [$kind, $name, $phone, $context, $message, $status]) {
            Lead::create([
                'kind' => $kind,
                'name' => $name,
                'phone' => $phone,
                'company' => $kind === 'b2b' ? 'ТОВ «Спецбуд»' : null,
                'email' => $kind === 'b2b' ? 'natalia@specbud.example' : null,
                'context' => $context,
                'message' => $message,
                'status' => $status,
            ]);
        }
    }
}
