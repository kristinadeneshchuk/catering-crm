<?php

namespace App\Services\Clients;

use App\Models\Client;
use App\Models\ClientLoginCode;
use App\Services\Messaging\Sms;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Одноразові коди входу в кабінет.
 *
 * Три речі, які тут важливі і які легко зробити неправильно:
 * код зберігається хешем; спроб на код рівно три; попередні коди того самого
 * номера гасяться при видачі нового — інакше «старий» код із SMS, яку клієнт
 * знайшов через тиждень, лишався б робочим.
 */
class LoginCodes
{
    public const LIFETIME_MINUTES = 10;

    public const MAX_ATTEMPTS = 3;

    public function __construct(private readonly Sms $sms) {}

    /**
     * Видає код і віддає його на канал доставки.
     *
     * Повертає сам код — він потрібен рівно в одному місці: на тестовому
     * майданчику, де SMS не ходять і код показується на екрані. На бойовому
     * це значення нікуди не потрапляє.
     */
    public function issue(string $phone, ?string $ip = null): string
    {
        $phone = Phone::normalize($phone) ?? $phone;

        // Старі коди цього номера більше не діють.
        ClientLoginCode::where('phone', $phone)->whereNull('used_at')->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        ClientLoginCode::create([
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => Carbon::now()->addMinutes(self::LIFETIME_MINUTES),
            'ip' => $ip,
        ]);

        $this->sms->send($phone, sprintf(
            'Код входу в кабінет БУР: %s. Діє %d хвилин. Нікому його не передавайте.',
            $code,
            self::LIFETIME_MINUTES
        ));

        return $code;
    }

    /**
     * Перевіряє код і повертає клієнта, якщо він підійшов.
     *
     * Клієнт створюється тут же, при першому вдалому вході: окремої реєстрації
     * немає — вона нічого не додає, крім ще однієї форми.
     */
    public function verify(string $phone, string $code): ?Client
    {
        $phone = Phone::normalize($phone) ?? $phone;

        $record = ClientLoginCode::where('phone', $phone)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->latest('id')
            ->first();

        if (! $record) {
            return null;
        }

        $record->increment('attempts');

        if (! Hash::check($code, $record->code_hash)) {
            return null;
        }

        $record->update(['used_at' => Carbon::now()]);

        $client = Client::firstOrCreate(['phone' => $phone]);
        $client->forceFill(['last_login_at' => Carbon::now()])->save();

        // Броні, зроблені до першого входу, мають знайтися в історії.
        $client->claimBookings();

        return $client;
    }

    /** Скільки ще спроб лишилось на активному коді. */
    public function attemptsLeft(string $phone): int
    {
        $phone = Phone::normalize($phone) ?? $phone;

        $record = ClientLoginCode::where('phone', $phone)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();

        return $record ? max(0, self::MAX_ATTEMPTS - $record->attempts) : 0;
    }
}
