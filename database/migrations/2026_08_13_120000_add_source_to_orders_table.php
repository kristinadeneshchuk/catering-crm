<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Звідки прийшло замовлення: null — оформлене руками в CRM,
     * 'telegram_inbox' — через Inbox API.
     *
     * Потрібне не для звітності, а щоб вебхук про оплату летів тільки по тих
     * замовленнях, які зовнішня система справді чекає. Без цього нічний
     * перерахунок балансів (RecalculateClientBalances) вистрелив би пачкою
     * подій по всій історії.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('status');
            $table->index('source');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
        });
    }
};
