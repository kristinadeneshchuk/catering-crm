<?php

namespace App\Services\Messenger;

use App\Models\MessengerAccount;
use App\Services\Messenger\Instagram\InstagramChannelDriver;
use App\Services\Messenger\Viber\ViberChannelDriver;
use InvalidArgumentException;

/**
 * Фабрика драйверів. Дає правильний драйвер за каналом акаунта.
 */
class ChannelDriverManager
{
    public function for(MessengerAccount $account): ChannelDriverInterface
    {
        return $this->forChannel($account->channel);
    }

    public function forChannel(string $channel): ChannelDriverInterface
    {
        return match ($channel) {
            MessengerAccount::CHANNEL_VIBER     => app(ViberChannelDriver::class),
            MessengerAccount::CHANNEL_INSTAGRAM => app(InstagramChannelDriver::class),
            // CHANNEL_TELEGRAM додається у наступній фазі (MadelineProto)
            default => throw new InvalidArgumentException("Драйвер для каналу '{$channel}' ще не реалізований"),
        };
    }
}
