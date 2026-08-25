<?php

namespace Tests\Feature;

use App\Filament\Pages\AvailabilityBoard;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Client;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@bur.local')->firstOrFail();
    }

    public function test_panel_is_closed_to_guests(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_deactivated_staff_cannot_enter(): void
    {
        $user = $this->admin();
        $user->update(['is_active' => false]);

        // Пароль лишився при звільненому — доступ має відрізати роль.
        $this->assertFalse($user->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_key_admin_screens_render(): void
    {
        $this->actingAs($this->admin());

        foreach ([
            '/admin',
            ProductResource::getUrl('index'),
            ProductResource::getUrl('edit', ['record' => Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail()]),
            BookingResource::getUrl('index'),
            AvailabilityBoard::getUrl(),
            '/admin/leads',
            '/admin/reviews',
            '/admin/faqs',
            '/admin/cities',
            '/admin/branches',
            '/admin/kits',
            ClientResource::getUrl('index'),
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_clients_screen_counts_rentals_and_revenue(): void
    {
        $this->actingAs($this->admin());

        $client = Client::create(['phone' => '380672458080', 'name' => 'Бригада Олега']);

        // Дві оренди: закрита — це вже гроші, скасована не рахується взагалі.
        $closed = Booking::whereNotNull('rent_total')->firstOrFail();
        $closed->forceFill(['client_id' => $client->id, 'status' => 'closed'])->save();

        $cancelled = Booking::where('id', '!=', $closed->id)->firstOrFail();
        $cancelled->forceFill(['client_id' => $client->id, 'status' => 'cancelled'])->save();

        $row = Client::query()
            ->withCount('bookings')
            ->withSum(
                ['bookings as revenue' => fn ($q) => $q->where('status', 'closed')],
                DB::raw('rent_total + extras_total + delivery_total')
            )
            ->findOrFail($client->id);

        $this->assertSame(2, $row->bookings_count);
        $this->assertSame(
            $closed->rent_total + $closed->extras_total + $closed->delivery_total,
            (int) $row->revenue
        );

        // Список має порахувати те саме, що й запит вище.
        $this->get(ClientResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Бригада Олега')
            ->assertSee('+380 67 245 80 80');

        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertOk();
    }

    public function test_client_card_lists_his_rentals(): void
    {
        $this->actingAs($this->admin());

        $client = Client::create(['phone' => '380672458080']);
        $booking = Booking::firstOrFail();
        $booking->forceFill(['client_id' => $client->id])->save();

        Livewire::test(BookingsRelationManager::class, [
            'ownerRecord' => $client,
            'pageClass' => EditClient::class,
        ])->assertSee($booking->number);
    }

    public function test_manager_can_pull_orphan_bookings_into_a_cabinet(): void
    {
        $client = Client::create(['phone' => '380672458080']);

        $orphan = Booking::whereNull('client_id')->firstOrFail();
        $orphan->forceFill(['phone' => '+380 67 245 80 80'])->save();

        // Менеджер виправив одруківку в телефоні — броні мають знайтися.
        $this->assertSame(1, $client->claimBookings());
        $this->assertSame($client->id, $orphan->fresh()->client_id);
    }

    public function test_availability_board_blocks_and_frees_a_service_day(): void
    {
        $this->actingAs($this->admin());

        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();
        $product = Product::where('slug', 'bosch-gll-3-80')->firstOrFail();
        $date = Carbon::today()->addDays(40)->toDateString();  // свідомо вільний день

        $component = Livewire::test(AvailabilityBoard::class)
            ->set('branchId', $branch->id)
            ->call('toggle', $product->id, $date);

        $this->assertDatabaseHas('unavailable_dates', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'date' => $date,
            'reason' => 'service',
        ]);

        $component->call('toggle', $product->id, $date);

        $this->assertDatabaseMissing('unavailable_dates', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'date' => $date,
        ]);
    }

    public function test_availability_board_refuses_to_free_a_rented_day(): void
    {
        $this->actingAs($this->admin());

        $branch = Branch::where('slug', 'poznyaky')->firstOrFail();
        $rented = UnavailableDate::where('branch_id', $branch->id)->where('reason', 'rented')->firstOrFail();

        Livewire::test(AvailabilityBoard::class)
            ->set('branchId', $branch->id)
            ->call('toggle', $rented->product_id, $rented->date->toDateString());

        // Інструмент фізично в руках у клієнта — з дошки його не звільняють.
        $this->assertDatabaseHas('unavailable_dates', ['id' => $rented->id]);
    }
}
