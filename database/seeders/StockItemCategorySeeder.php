<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class StockItemCategorySeeder extends Seeder
{
    public function run(): void
    {
        DB::table('stock_item_categories')->insertOrIgnore([
            ['name' => 'Інгредієнти / Продукти', 'icon' => '🍏', 'model_class' => 'App\Models\Ingredient', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Упаковка / Госптовари',  'icon' => '📦', 'model_class' => 'App\Models\Packaging',  'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
