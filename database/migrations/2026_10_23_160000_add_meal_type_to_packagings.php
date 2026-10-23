<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Наліпки на бокси друкуються під бренд і прийом їжі («Сніданок», «Обід», «Вечеря 2»…):
// картка упаковки знає свій прийом і номер бокса в прийомі (2 — «Вечеря 2»).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->foreignId('meal_type_id')->nullable()->after('project')->constrained('meal_types')->nullOnDelete();
            $table->unsignedTinyInteger('meal_box_no')->nullable()->after('meal_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('packagings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meal_type_id');
            $table->dropColumn('meal_box_no');
        });
    }
};
