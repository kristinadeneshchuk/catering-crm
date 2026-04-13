<?php

use Illuminate\Database\Migrations\Migration;
use App\Models\Setting;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'monthly_rent'],
            ['value' => '0']
        );
        Setting::firstOrCreate(
            ['key' => 'monthly_utilities'],
            ['value' => '0']
        );
    }

    public function down(): void
    {
        Setting::whereIn('key', ['monthly_rent', 'monthly_utilities'])->delete();
    }
};
