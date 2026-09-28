<?php

namespace App\Console\Commands;

use App\Services\TelegramService;
use Illuminate\Console\Command;

/**
 * Викликає fail2ban (jail crm-login), коли банить IP за підбір пароля:
 *   sudo -u www-data php8.5 artisan security:ban-notify <ip> --failures=<n> --bantime=<сек>
 *
 * Показує власнику, під які логіни пробували — так видно, чи цілили в
 * конкретний акаунт.
 */
class SecurityBanNotify extends Command
{
    protected $signature = 'security:ban-notify
        {ip : Заблокований IP}
        {--failures= : Скільки спроб набрав}
        {--bantime= : На скільки секунд забанено}';

    protected $description = 'Сповіщення власнику: fail2ban заблокував IP за підбір пароля до CRM';

    public function handle(TelegramService $telegram): int
    {
        $ip = (string) $this->argument('ip');
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->error('Некоректний IP');
            return self::FAILURE;
        }

        $logins = $this->triedLogins($ip);
        $hours = $this->option('bantime') ? round(((int) $this->option('bantime')) / 3600) : null;

        $text = "🚫 Заблоковано IP {$ip}"
            . ($hours ? " на {$hours} год" : '') . "\n"
            . 'Підбір пароля до CRM' . ($this->option('failures') ? ": {$this->option('failures')} невдалих спроб" : '') . "\n"
            . ($logins ? 'Пробували логіни: ' . implode(', ', $logins) . "\n" : '')
            . "\nЗламу немає — вхід не вдався. Це повідомлення для контролю.";

        $telegram->sendToOwner($text);
        $this->info($text);

        return self::SUCCESS;
    }

    /** Унікальні логіни з останніх рядків журналу безпеки для цього IP (до 5). */
    private function triedLogins(string $ip): array
    {
        $path = storage_path('logs/security.log');
        if (! is_readable($path)) return [];

        $tail = array_slice(file($path, FILE_IGNORE_NEW_LINES) ?: [], -2000);
        $found = [];
        foreach (array_reverse($tail) as $line) {
            if (preg_match('/LOGIN_FAILED ip=' . preg_quote($ip, '/') . ' guard=\S+ login="([^"]*)"/', $line, $m)) {
                $found[$m[1]] = true;
                if (count($found) >= 5) break;
            }
        }

        return array_keys($found);
    }
}
