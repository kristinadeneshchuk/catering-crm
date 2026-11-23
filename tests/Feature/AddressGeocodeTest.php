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

    public function test_typed_address_is_found_by_street_and_house_when_free_text_fails(): void
    {
        // Рядком Nominatim нічого не віддає, окремо вулицею й будинком — знаходить.
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            return Http::response(isset($q['street']) && $q['street'] === '26 вулиця Джона Маккейна' && $q['city'] === 'Київ'
                ? [['lat' => '50.4182562', 'lon' => '30.5392310']]
                : [], 200);
        });

        $this->assertSame(
            [50.4182562, 30.539231],
            app(\App\Services\Geo\AddressGeocoder::class)->geocode('м. Київ, вулиця Джона Маккейна, 26'),
        );
    }

    public function test_street_and_house_are_parsed_from_typed_addresses(): void
    {
        $g = \App\Services\Geo\AddressGeocoder::class;

        $this->assertSame(['вулиця Ніжинська', '7', null], $g::streetAndHouse('вул. Ніжинська буд. 7'));
        $this->assertSame(['вулиця Зої Бутенко', '10к1', null], $g::streetAndHouse('м. Київ, вулиця Зої Бутенко, 10к1 (Голосіївський р-н)'));
        $this->assertSame(['бульвар Лесі Українки', '30', null], $g::streetAndHouse('бульвар Лесі Українки 30'));
        $this->assertSame(['вулиця Зелена', '6', 'Софіївська Борщагівка'], $g::streetAndHouse('Софіївська Борщагівка, вул. Зелена, 6'));
        $this->assertNull($g::streetAndHouse('Київ'));
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

    public function test_address_left_without_coordinates_is_reported_to_managers_once(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        config(['services.telegram.bot_token' => 'test', 'services.telegram.manager_chat_id' => '-100']);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([], 200),
            '*'                             => Http::response(['ok' => true]),
        ]);

        $a = ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'Київ, Ніженська 7']);
        $a->update(['delivery_comment' => 'з рук в руки']);   // текст той самий — мовчимо

        $texts = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), 'api.telegram.org'))
            ->map(fn ($pair) => (string) ($pair[0]->data()['text'] ?? ''))->values();

        $this->assertNull($a->fresh()->lat);
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('Адреса без координат', $texts[0]);
        $this->assertStringContainsString('Київ, Ніженська 7', $texts[0]);
    }

    public function test_found_address_is_not_reported(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        config(['services.telegram.bot_token' => 'test', 'services.telegram.manager_chat_id' => '-100']);
        Http::fake([
            'nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.44', 'lon' => '30.43']], 200),
            '*'                             => Http::response(['ok' => true]),
        ]);

        ClientAddress::create(['client_id' => 1, 'label' => 'Адреса', 'address' => 'Київ, вул. Ніжинська, 7']);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
    }
}
