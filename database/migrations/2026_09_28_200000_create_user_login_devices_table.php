<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пристрої, з яких персонал уже входив. Вхід з пристрою, якого тут немає,
 * — сповіщення власнику в Telegram (NotifyNewDeviceLogin).
 *
 * Засіваємо з поточних сесій: інакше першого ж дня після деплою кожен
 * знайомий телефон менеджерки прийшов би власнику як «новий пристрій».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_login_devices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->char('ua_hash', 40);
            $t->text('user_agent')->nullable();
            $t->string('last_ip', 45)->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->unique(['user_id', 'ua_hash']);
        });

        if (! Schema::hasTable('sessions')) {
            return;
        }

        $now = now();
        $known = DB::table('sessions')
            ->whereNotNull('user_id')
            ->whereIn('user_id', DB::table('users')->select('id'))
            ->get(['user_id', 'user_agent', 'ip_address', 'last_activity']);

        foreach ($known as $s) {
            DB::table('user_login_devices')->insertOrIgnore([
                'user_id'       => $s->user_id,
                'ua_hash'       => sha1((string) $s->user_agent),
                'user_agent'    => $s->user_agent,
                'last_ip'       => $s->ip_address,
                'first_seen_at' => $now,
                'last_seen_at'  => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_devices');
    }
};
