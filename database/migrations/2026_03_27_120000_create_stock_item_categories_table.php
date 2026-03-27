<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('icon')->default('📦');
            $table->string('model_class'); // App\Models\Ingredient or App\Models\Packaging
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_item_categories');
    }
};
