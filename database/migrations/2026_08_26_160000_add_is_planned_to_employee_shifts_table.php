<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Запланована зміна проти відпрацьованої.
     *
     * Графік ставлять наперед, і план не має чіпати гроші: клік по табелю
     * зараз одразу робить increment('balance'), тож автоматичне перетворення
     * плану у факт нарахувало б зарплату курʼєру, який не вийшов. Спливло б
     * це аж на виплаті.
     *
     * Тому план живе окремим прапорцем і стає фактом лише через підтвердження
     * виходу — саме в цей момент нараховується ставка.
     *
     * Усі наявні 951 зміна — це факт, тож default false правильний.
     */
    public function up(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->boolean('is_planned')->default(false)->after('is_half');
            $table->index(['date', 'is_planned']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_shifts', function (Blueprint $table) {
            $table->dropIndex(['date', 'is_planned']);
            $table->dropColumn('is_planned');
        });
    }
};
