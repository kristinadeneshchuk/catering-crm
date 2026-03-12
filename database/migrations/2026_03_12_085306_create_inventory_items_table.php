<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained()->cascadeOnDelete();
            
            // Магія поліморфізму: збереже тип (Ingredient або Packaging) і його ID
            $table->morphs('itemable'); 
            
            $table->string('name'); // Назва товару (щоб не бігати в базу при відображенні)
            $table->string('unit')->nullable(); // г, кг, шт, л
            
            // Ключові цифри
            $table->decimal('expected_qty', 10, 3)->default(0); // План (залишок з бази)
            $table->decimal('actual_qty', 10, 3)->nullable();   // Факт (вводить кухар)
            $table->decimal('price', 10, 2)->default(0);        // Собівартість на момент інвентаризації
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};