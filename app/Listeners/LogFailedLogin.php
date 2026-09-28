<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;

/**
 * Кожна невдала спроба входу — рядок у storage/logs/security.log.
 *
 * У логах nginx невдалий і вдалий вхід через Livewire виглядають однаково
 * (POST /livewire/update → 200), тож без цього рядка fail2ban нема за що
 * вхопитися. IP стоїть перед логіном навмисно: логін вводить атакуючий, а
 * фільтр fail2ban має впізнавати рядок до того, як почнеться чужий текст.
 *
 * IP — з TCP-з'єднання (довірених проксі в застосунку немає), тож
 * заголовком X-Forwarded-For його не підробити.
 */
class LogFailedLogin
{
    public function handle(Failed $event): void
    {
        $login = (string) ($event->credentials['email'] ?? $event->credentials['login'] ?? $event->credentials['phone'] ?? '');
        $login = mb_substr(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', $login), 0, 80);

        Log::channel('security')->warning(sprintf(
            'LOGIN_FAILED ip=%s guard=%s login="%s"',
            request()->ip() ?? '-',
            $event->guard,
            str_replace('"', "'", $login),
        ));
    }
}
