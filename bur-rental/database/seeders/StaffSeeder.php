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
        User::updateOrCreate(['email' => 'admin@bur.local'], [
            'name' => 'Адміністратор',
            'password' => Hash::make('password'),
            'role' => 'admin',
        ]);

        // Менеджер філії — щоб було на кому перевірити обмеження прав.
        User::updateOrCreate(['email' => 'manager@bur.local'], [
            'name' => 'Андрій, Позняки',
            'password' => Hash::make('password'),
            'role' => 'manager',
            'branch_id' => Branch::where('slug', 'poznyaky')->value('id'),
        ]);
    }
}
