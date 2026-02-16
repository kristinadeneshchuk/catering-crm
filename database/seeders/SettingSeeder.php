<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Setting; // Не забудьте імпортувати модель

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Оновлюємо або створюємо кількість днів циклу
        Setting::updateOrCreate(
            ['key' => 'menu_cycle_days'],
            ['value' => '24']
        );

        // Оновлюємо або створюємо дату початку відліку
        Setting::updateOrCreate(
            ['key' => 'menu_cycle_start_date'],
            ['value' => '2025-01-01']
        );
    }
}