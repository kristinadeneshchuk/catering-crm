<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ролі персоналу. Адмін бачить усе; менеджер працює з бронями, заявками
 * і наявністю, але не редагує ціни й структуру каталогу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('manager')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
            $table->foreignId('branch_id')->nullable()->after('is_active')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
