<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Що в чернетці ще чекає на людину: рядки, де невідоме фасування, і
     * повідомлення в Telegram, реплаєм на яке приходить відповідь.
     */
    public function up(): void
    {
        Schema::table('stock_documents', function (Blueprint $table) {
            $table->json('ai_state')->nullable()->after('ai_comment');
        });
    }

    public function down(): void
    {
        Schema::table('stock_documents', fn (Blueprint $t) => $t->dropColumn('ai_state'));
    }
};
