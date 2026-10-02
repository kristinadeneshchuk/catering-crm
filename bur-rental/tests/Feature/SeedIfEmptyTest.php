<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Деплой викликає catalog:seed-if-empty щоразу. Другий виклик не має права
 * затерти те, що менеджер змінив в адмінці.
 */
class SeedIfEmptyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_run_fills_the_catalog_and_later_runs_keep_admin_edits(): void
    {
        $this->artisan('catalog:seed-if-empty')->assertSuccessful();
        $this->assertSame(48, Product::count());

        $product = Product::where('slug', 'bosch-gbh-2-26-dre')->firstOrFail();
        $product->update(['lead' => 'Текст, який менеджер виправив в адмінці']);

        $this->artisan('catalog:seed-if-empty')
            ->expectsOutputToContain('пропущено')
            ->assertSuccessful();

        $this->assertSame(48, Product::count());
        $this->assertSame('Текст, який менеджер виправив в адмінці', $product->fresh()->lead);
    }

    public function test_production_without_admin_password_fails_before_touching_the_database(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        putenv('ADMIN_PASSWORD');
        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);

        $this->artisan('catalog:seed-if-empty')->assertFailed();

        // Нічого не налито — наступний деплой з паролем у .env засіє все наново.
        $this->assertSame(0, Product::withoutGlobalScopes()->count());
    }
}
