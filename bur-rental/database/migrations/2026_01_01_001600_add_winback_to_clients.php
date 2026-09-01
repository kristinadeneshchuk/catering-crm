<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Повернення клієнта після паузи.
 *
 * `win_back_sent_at` тримає останню розсилку, щоб людина не отримувала
 * «ми скучили» щотижня. `marketing_opt_out` — право сказати «не пишіть»:
 * без нього розсилка перетворюється на спам, а спам у прокаті коштує
 * дорожче за втраченого клієнта — він забирає ще й репутацію номера.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->timestamp('win_back_sent_at')->nullable()->after('last_login_at');
            $table->boolean('marketing_opt_out')->default(false)->after('win_back_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['win_back_sent_at', 'marketing_opt_out']);
        });
    }
};
