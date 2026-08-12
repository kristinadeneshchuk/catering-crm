<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Бренд, у який людина написала вперше.
     *
     * Один Telegram-акаунт = один бренд (A Food / avocado food / u-fit), тому
     * вхідні повідомлення одразу знають свій проєкт і менеджер не обирає його
     * руками. Зберігаємо slug, як і в orders.project / tariffs.project.
     *
     * Поле довідкове, не ключ пошуку: на таблиці вже висить UNIQUE
     * (channel, external_id), тобто один Telegram-ID — рівно один рядок,
     * незалежно від бренду. Той самий клієнт може писати у два бренди з одного
     * акаунта, і це буде один канал із проєктом першого звернення.
     */
    public function up(): void
    {
        Schema::table('client_channels', function (Blueprint $table) {
            $table->string('project')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('client_channels', function (Blueprint $table) {
            $table->dropColumn('project');
        });
    }
};
