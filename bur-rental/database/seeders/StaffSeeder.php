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
        User::updateOrCreate(['email' => env('ADMIN_EMAIL', 'admin@bur.local')], [
            'name' => 'Адміністратор',
            'password' => Hash::make(env('ADMIN_PASSWORD', 'password')),
            'role' => 'admin',
        ]);

        // Менеджер філії — щоб було на кому перевірити обмеження прав.
        User::updateOrCreate(['email' => env('MANAGER_EMAIL', 'manager@bur.local')], [
            'name' => 'Андрій, Позняки',
            'password' => Hash::make(env('MANAGER_PASSWORD', 'password')),
            'role' => 'manager',
            'branch_id' => Branch::where('slug', 'poznyaky')->value('id'),
        ]);
    }
}
