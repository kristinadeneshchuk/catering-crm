<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\TelegramService;
use App\Support\Security\DeviceLabel;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;

/**
 * Вхід персоналу з пристрою, якого ще не бачили, — одразу власнику в Telegram.
 *
 * Бан за підбором пароля закриває лише грубу силу; якщо пароль таки вкрали,
 * дізнатися про це можна тільки так. Пристрій = User-Agent: IP мобільного
 * інтернету міняється постійно, і на нього сповіщення сипались би щодня.
 * Оновлення iOS чи браузера інколи дасть зайве повідомлення — це прийнятна ціна.
 */
class NotifyNewDeviceLogin
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return; // клієнтський кабінет — не персонал
        }

        $ua = (string) request()->userAgent();
        $ip = request()->ip();
        $hash = sha1($ua);
        $now = now();

        $known = DB::table('user_login_devices')
            ->where('user_id', $event->user->id)
            ->where('ua_hash', $hash)
            ->exists();

        if ($known) {
            DB::table('user_login_devices')
                ->where('user_id', $event->user->id)
                ->where('ua_hash', $hash)
                ->update(['last_ip' => $ip, 'last_seen_at' => $now]);

            return;
        }

        DB::table('user_login_devices')->insertOrIgnore([
            'user_id'       => $event->user->id,
            'ua_hash'       => $hash,
            'user_agent'    => $ua,
            'last_ip'       => $ip,
            'first_seen_at' => $now,
            'last_seen_at'  => $now,
        ]);

        $text = "🔐 Вхід у CRM з нового пристрою\n"
            . "Акаунт: {$event->user->name} ({$event->user->role})\n"
            . 'Пристрій: ' . DeviceLabel::fromUserAgent($ua) . "\n"
            . "IP: {$ip}\n"
            . 'Час: ' . $now->format('d.m H:i') . "\n\n"
            . 'Якщо це не ваші люди — терміново змініть пароль цього акаунта.';

        // Після відповіді й без черги: логін не чекає на Telegram, а збій
        // Telegram не ламає вхід.
        $telegram = $this->telegram;
        app()->terminating(function () use ($telegram, $text) {
            try {
                $telegram->sendToOwner($text);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
