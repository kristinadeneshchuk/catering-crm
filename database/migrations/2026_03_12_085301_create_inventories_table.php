<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->dateTime('operation_date'); // Дата і час проведення
            $table->string('type')->default('full'); // 'full' (Повна) або 'partial' (Часткова)
            $table->json('selected_groups')->nullable(); // Обрані категорії для часткової
            
            $table->string('status')->default('draft'); // 'draft' (чернетка) або 'completed' (проведена)
            
            // Фінансові результати (будемо оновлювати автоматично)
            $table->decimal('total_surplus', 12, 2)->default(0); // Надлишок (+)
            $table->decimal('total_shortage', 12, 2)->default(0); // Нестача (-)
            
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};