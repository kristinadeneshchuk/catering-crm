<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Платіжні реквізити бренду.
     *
     * У кожного бренду свій ФОП і свій рахунок, тому реквізити живуть на
     * проєкті, а не в глобальних налаштуваннях. У рахунок вони потрапляють
     * знімком: якщо ФОП зміниться, старі рахунки мають лишитись такими, якими
     * їх бачив клієнт.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('recipient_name')->nullable()->after('color');
            $table->string('iban', 64)->nullable()->after('recipient_name');
            $table->string('tax_id', 32)->nullable()->after('iban');
            $table->string('bank_name')->nullable()->after('tax_id');
            $table->string('mfo', 16)->nullable()->after('bank_name');
            $table->string('payment_purpose')->nullable()->after('mfo');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'recipient_name', 'iban', 'tax_id', 'bank_name', 'mfo', 'payment_purpose',
            ]);
        });
    }
};
