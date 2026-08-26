<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Сітка тарифів другої версії фасування.
     *
     * Один рядок = один тариф: які прийоми входять і якого розміру кожен.
     * Наприклад 2400 = Сніданок L + Перекус Std + Обід L + Полуденок Std +
     * Вечеря L.
     *
     * Навмисно таблиця, а не обчислення. Сітка кодує рішення, які з
     * арифметики не виводяться: 1000 ккал збирається як сніданок + полуденок +
     * вечеря, хоча ті самі 1000 дають сніданок + обід. Чому саме так — знає
     * власник, а не розвʼязувач. І кухні потрібна передбачуваність: «вчора обід
     * був звичайний, сьогодні великий» без пояснення нікому не потрібно.
     */
    public function up(): void
    {
        Schema::create('portion_grids', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('calories')->unique();

            // Колір кришки/стікера: збірка замовлення за кольором, без читання тексту.
            $table->string('color', 32)->nullable();
            $table->string('color_label')->nullable();

            // Додаткові снеки — друга порція перекусу дня, та сама страва.
            $table->unsignedTinyInteger('extra_snacks_std')->default(0);
            $table->unsignedTinyInteger('extra_snacks_large')->default(0);

            $table->boolean('is_active')->default(true);
            $table->text('comment')->nullable();

            $table->timestamps();
        });

        Schema::create('portion_grid_slots', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('portion_grid_id');
            $table->unsignedBigInteger('meal_type_id');

            // std | large. Відсутність рядка = прийом у цей тариф не входить.
            $table->string('size', 8);

            $table->timestamps();

            $table->unique(['portion_grid_id', 'meal_type_id']);
            $table->index('meal_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portion_grid_slots');
        Schema::dropIfExists('portion_grids');
    }
};
