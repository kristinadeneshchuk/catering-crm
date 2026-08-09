<?php

namespace Tests\Feature;

use App\Filament\Pages\AvailabilityBoard;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Branch;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        ] as $url) {
            $this->get($url)->assertOk();
        }
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
