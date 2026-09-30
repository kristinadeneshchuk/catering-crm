<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        /*
         | Паролі беруться з оточення. На тестовому й бойовому хості їх
         | задають у .env — у репозиторії лежить тільки локальна заглушка,
         | і сид ніколи не «понижує» пароль живого сайту до демонстраційного.
         */
        // На бойовому без пароля з .env не створюємо нікого: заглушка
        // «password» відкриває адмінку першому ж, хто спробує.
        if (app()->isProduction() && (! env('ADMIN_EMAIL') || ! env('ADMIN_PASSWORD'))) {
            throw new \RuntimeException('Задайте ADMIN_EMAIL і ADMIN_PASSWORD у .env перед db:seed на бойовому сайті.');
        }

        User::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@bur.local')], [
            'name' => 'Адміністратор',
            'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            'role' => 'admin',
        ]);

        // Демо-менеджер на бойовому — тільки якщо йому задали пароль.
        if (app()->isProduction() && ! env('MANAGER_PASSWORD')) {
            return;
        }

        // Менеджер філії — щоб було на кому перевірити обмеження прав.
        User::updateOrCreate(['email' => env('MANAGER_EMAIL', 'manager@bur.local')], [
            'name' => 'Андрій, Позняки',
            'password' => Hash::make(env('MANAGER_PASSWORD', 'password')),
            'role' => 'manager',
            'branch_id' => Branch::where('slug', 'poznyaky')->value('id'),
        ]);
    }
}
