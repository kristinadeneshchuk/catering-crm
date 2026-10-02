<?php

namespace App\Console\Commands;

use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Наповнює базу каталогом і текстами — тільки якщо вона порожня.
 *
 * Деплой запускає цю команду щоразу. Перший раз вона наливає каталог,
 * усі наступні — нічого не робить: повторний db:seed перезаписав би те,
 * що менеджер уже виправив в адмінці (ціни, тексти, контакти філій).
 */
class SeedIfEmpty extends Command
{
    protected $signature = 'catalog:seed-if-empty';

    protected $description = 'Наповнити каталог, якщо база порожня (безпечно запускати на кожному деплої)';

    public function handle(): int
    {
        if (Product::withoutGlobalScopes()->exists()) {
            $this->info('Каталог уже є — наповнення пропущено.');

            return self::SUCCESS;
        }

        // Перевірка до початку, а не посередині: інакше каталог уже налитий,
        // адміна немає, а наступний деплой бачить «каталог є» і більше не сіє.
        if (app()->isProduction() && (! env('ADMIN_EMAIL') || ! env('ADMIN_PASSWORD'))) {
            $this->error('Задайте ADMIN_EMAIL і ADMIN_PASSWORD у .env — без них адмінку не створити.');

            return self::FAILURE;
        }

        $this->info('База порожня — наповнюю каталог і тексти.');

        // Усе або нічого: збій посередині не має лишити «напівналиту» базу.
        DB::transaction(fn () => $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]));

        return self::SUCCESS;
    }
}
