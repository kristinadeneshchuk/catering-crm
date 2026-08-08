<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Extra;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Services\RentalPricing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(private readonly RentalPricing $pricing) {}

    public function create(Request $request): View
    {
        $city = $request->attributes->get('city');

        return view('pages.booking', [
            'branches' => $city->branches,
            'zones' => $city->deliveryZones,
            // Кошик живе в localStorage, тому сторінка вміє дістати товар
            // за slug'ом і показати актуальну ціну, а не збережену торік.
            'catalog' => Product::with('tiers')->get()
                ->mapWithKeys(fn (Product $p) => [$p->slug => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'deposit' => $p->deposit,
                    'weight' => $p->weight_kg,
                    'tiers' => $p->tiers->map->only(['min_days', 'max_days', 'price']),
                ]]),
            'extras' => Extra::orderBy('name')->get(),
        ]);
    }

    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $items = collect($data['items'])->map(fn (array $row) => [
            'product' => Product::with('tiers')->findOrFail($row['product_id']),
            'qty' => (int) $row['qty'],
            'from' => $row['from'],
            'to' => $row['to'],
            'days' => $this->pricing->days($row['from'], $row['to']),
        ]);

        $extras = collect($data['extras'] ?? [])->map(fn (array $row) => [
            'extra' => Extra::findOrFail($row['extra_id']),
            'qty' => (int) $row['qty'],
        ]);

        // Друга перевірка наявності: між додаванням у кошик і натисканням
        // «Забронювати» міг пройти день, і позицію встигли забрати.
        $branch = Branch::findOrFail($data['branch_id']);
        $taken = $items->reject(
            fn (array $i) => $i['product']->loadMissing('unavailableDates')
                ->isFreeAt($branch, $i['from'], $i['to'])
        );

        $zone = $data['fulfilment'] === 'delivery'
            ? DeliveryZone::find($data['delivery_zone_id'])
            : null;

        $days = $items->max('days');
        $totals = $this->pricing->itemsTotal($items);

        $booking = DB::transaction(function () use ($data, $items, $extras, $zone, $days, $totals) {
            $booking = Booking::create([
                'number' => $this->nextNumber(),
                'branch_id' => $data['branch_id'],
                'client_type' => $data['client_type'],
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'company' => $data['company'] ?? null,
                'edrpou' => $data['edrpou'] ?? null,
                'fulfilment' => $data['fulfilment'],
                'delivery_zone_id' => $zone?->id,
                'address' => $data['address'] ?? null,
                'payment' => $data['payment'],
                'deposit_way' => $data['deposit_way'],
                'date_from' => $items->min('from'),
                'date_to' => $items->max('to'),
                'rent_total' => $totals['rent'],
                'extras_total' => $this->pricing->extrasTotal($extras),
                'delivery_total' => $this->pricing->delivery($zone, $items, $days),
                'deposit_total' => $totals['deposit'],
                'comment' => $data['comment'] ?? null,
            ]);

            foreach ($items as $item) {
                $booking->items()->create([
                    'product_id' => $item['product']->id,
                    'title' => $item['product']->name,
                    'qty' => $item['qty'],
                    'days' => $item['days'],
                    'price_per_day' => $this->pricing->pricePerDay($item['product'], $item['days']),
                    'total' => $this->pricing->rentTotal($item['product'], $item['days'], $item['qty']),
                    'deposit' => $item['product']->deposit * $item['qty'],
                ]);

                // Позиція зникає з календаря одразу — інакше її забронюють двічі.
                $this->blockDates($booking, $item);
            }

            foreach ($extras as $extra) {
                $booking->items()->create([
                    'extra_id' => $extra['extra']->id,
                    'title' => $extra['extra']->name,
                    'qty' => $extra['qty'],
                    'days' => 1,
                    'price_per_day' => $extra['extra']->price,
                    'total' => $extra['extra']->price * $extra['qty'],
                ]);
            }

            return $booking;
        });

        return redirect()
            ->route('booking.show', $booking)
            ->with('taken', $taken->pluck('product.name')->all());
    }

    public function show(Booking $booking): View
    {
        return view('pages.booking-confirmed', [
            'booking' => $booking->load(['items.product', 'items.extra', 'branch.city', 'deliveryZone']),
        ]);
    }

    private function blockDates(Booking $booking, array $item): void
    {
        $cursor = Carbon::parse($item['from']);
        $end = Carbon::parse($item['to']);

        while ($cursor->lte($end)) {
            UnavailableDate::firstOrCreate([
                'product_id' => $item['product']->id,
                'branch_id' => $booking->branch_id,
                'date' => $cursor->toDateString(),
            ], ['reason' => 'rented']);

            $cursor->addDay();
        }
    }

    private function nextNumber(): string
    {
        $year = now()->format('y');
        $seq = Booking::whereYear('created_at', now()->year)->count() + 1;

        return sprintf('BUR-%s-%06d', $year, $seq);
    }
}
