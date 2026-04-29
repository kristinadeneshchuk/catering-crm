<?php

namespace App\Console\Commands;

use App\Models\MessengerAccount;
use App\Services\Messenger\Instagram\InstagramChannelDriver;
use Illuminate\Console\Command;

/**
 * Раз на добу оновлюємо long-lived user access token + page access token
 * для всіх Instagram-акаунтів. Long-lived живе 60 днів — ми оновлюємо із запасом.
 *
 * Запуск через scheduler — додай у app/Console/Kernel.php або routes/console.php:
 *     Schedule::command('messenger:refresh-instagram-tokens')->daily();
 */
class RefreshInstagramTokens extends Command
{
    protected $signature   = 'messenger:refresh-instagram-tokens';
    protected $description = 'Оновлює access tokens для всіх Instagram messenger-акаунтів';

    public function handle(InstagramChannelDriver $driver): int
    {
        $accounts = MessengerAccount::query()
            ->where('channel', MessengerAccount::CHANNEL_INSTAGRAM)
            ->whereIn('status', [MessengerAccount::STATUS_ACTIVE, MessengerAccount::STATUS_EXPIRED])
            ->get();

        if ($accounts->isEmpty()) {
            $this->info('Немає Instagram-акаунтів для оновлення.');
            return self::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->info("→ {$account->display_name} (#{$account->id})");

            try {
                $driver->refreshToken($account);
                $this->info('  ✓ оновлено');
            } catch (\Throwable $e) {
                $this->error('  ✗ помилка: ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
