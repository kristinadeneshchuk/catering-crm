<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Бренд месенджер-акаунта.
     *
     * Один Telegram-акаунт = один бренд (A Food / avocado food / u-fit), тож
     * конструктор замовлення в чаті знає проєкт одразу і менеджер його не
     * обирає. Змінити вручну все одно можна — але як виняток, а не щоразу.
     */
    public function up(): void
    {
        Schema::table('messenger_accounts', function (Blueprint $table) {
            $table->string('project')->nullable()->after('display_name');
        });
    }

    public function down(): void
    {
        Schema::table('messenger_accounts', function (Blueprint $table) {
            $table->dropColumn('project');
        });
    }
};
