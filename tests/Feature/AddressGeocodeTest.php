<?php

namespace Tests\Feature;

use App\Models\ClientAddress;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Адреса, вписана вручну (без вибору з пошуку чи маркера), раніше зберігалась
 * без координат і не потрапляла в маршрути (мураха). Тепер координати
 * підставляються під час збереження.
 */
class AddressGeocodeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.geocoder.enabled', true);

        if (! Schema::hasTable('client_addresses')) {
            Schema::create('client_addresses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('client_id');
                $t->string('label')->nullable();
                $t->string('address');
                $t->decimal('lat', 10, 7)->nullable();
                $t->decimal('lng', 10, 7)->nullable();
                $t->string('address_entrance')->nullable();
                $t->string('address_apartment')->nullable();
                $t->string('address_floor')->nullable();
                $t->string('delivery_comment')->nullable();
                $t->boolean('needs_pass')->default(false);
                $t->boolean('is_default')->default(false);
                $t->string('ant_comp_id')->nullable();
                $t->timestamps();
            });
        }
    }

    private function fakeNominatim(?array $hit = ['lat' => '50.5097', 'lon' => '30.4986']): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($hit ? [$hit] : [], 200)]);
    }

    public function test_manual_address_gets_coordinates(): void
    {
        $this->fakeNominatim();

        $a = ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'Оболонський проспект 1, корпус 1']);

        $this->assertEqualsWithDelta(50.5097, (float) $a->fresh()->lat, 0.0001);
        $this->assertEqualsWithDelta(30.4986, (float) $a->fresh()->lng, 0.0001);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'nominatim') && str_contains(urldecode($r->url()), 'Оболонський'));
    }

    public function test_chosen_coordinates_are_kept(): void
    {
        $this->fakeNominatim();

        $a = ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'вул. Зелена, 6', 'lat' => 50.4, 'lng' => 30.3]);

        $this->assertEqualsWithDelta(50.4, (float) $a->fresh()->lat, 0.0001);
        Http::assertNothingSent();
    }

    public function test_edited_text_regeocodes_and_unknown_clears_stale_coords(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::sequence()
            ->push([['lat' => '50.42', 'lon' => '30.55']])
            ->push([])
            ->push([])]);
        $a = ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'вул. Зелена, 6', 'lat' => 50.4, 'lng' => 30.3]);

        $a->update(['address' => 'вул. Василя Тютюнника, 5В']);
        $this->assertEqualsWithDelta(50.42, (float) $a->fresh()->lat, 0.0001);

        $a->update(['address' => 'невідома адреса 123']);
        $this->assertNull($a->fresh()->lat);
    }

    public function test_untouched_address_does_not_call_geocoder(): void
    {
        $this->fakeNominatim();
        $a = ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'вул. Зелена, 6', 'lat' => 50.4, 'lng' => 30.3]);

        $a->update(['delivery_comment' => 'домофон 12']);

        Http::assertNothingSent();
    }
}
