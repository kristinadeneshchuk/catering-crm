<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Product;
use App\Models\UnavailableDate;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * db:seed на бойовому сайті запускається один раз (setup.php) і мусить дати
 * каталог і тексти — без вигаданих броней, вигаданої зайнятості й паролів-заглушок.
 */
class ProductionSeedTest extends TestCase
{
    use RefreshDatabase;

    private function env(string $key, ?string $value): void
    {
        foreach ([&$_ENV, &$_SERVER] as &$bag) {
            if ($value === null) {
                unset($bag[$key]);
            } else {
                $bag[$key] = $value;
            }
        }
        putenv($value === null ? $key : "$key=$value");
    }

    /** Напряму, без artisan: у production db:seed питає підтвердження. */
    private function seedAsProduction(): void
    {
        $this->app->make(DatabaseSeeder::class)->setContainer($this->app)->__invoke();
    }

    protected function tearDown(): void
    {
        foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD', 'MANAGER_PASSWORD'] as $key) {
            $this->env($key, null);
        }

        parent::tearDown();
    }

    public function test_production_seed_has_catalog_but_no_invented_bookings_or_busy_days(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->env('ADMIN_EMAIL', 'owner@tekhpark.com.ua');
        $this->env('ADMIN_PASSWORD', 'довгий-непростий-пароль-2026');

        $this->seedAsProduction();

        $this->assertSame(48, Product::count());
        $this->assertSame(0, Booking::count());
        $this->assertSame(0, UnavailableDate::count());

        // Адмін з .env є, демо-менеджера з паролем «password» — немає.
        $this->assertTrue(User::where('email', 'owner@tekhpark.com.ua')->exists());
        $this->assertSame(1, User::count());
    }

    public function test_production_seed_refuses_to_create_an_admin_without_a_password(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->env('ADMIN_EMAIL', null);
        $this->env('ADMIN_PASSWORD', null);

        $this->expectException(\RuntimeException::class);

        $this->seedAsProduction();
    }
}
