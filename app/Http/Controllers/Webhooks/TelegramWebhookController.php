<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessengerAccount;
use App\Services\Messenger\Dto\InboundMessageData;
use App\Services\Messenger\InboundMessageHandler;
use App\Services\Messenger\Telegram\TelegramChannelDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Telegram б'є на /webhooks/telegram/{account}.
 *
 * Відповідаємо 200 практично завжди: на будь-який інший код Telegram буде
 * ретраїти те саме оновлення, а дублікати нам ні до чого — InboundMessageHandler
 * і так дедуплікує по message_id, але зайвий трафік нікому не потрібен.
 */
class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramChannelDriver $driver,
        protected InboundMessageHandler $handler,
    ) {
    }

    public function handle(Request $request, MessengerAccount $account)
    {
        if ($account->channel !== MessengerAccount::CHANNEL_TELEGRAM) {
            return response()->json(['ok' => false, 'reason' => 'wrong channel']);
        }

        // Telegram за зовнішнім Inbox — навіть якщо десь лишився старий webhook
        // на CRM, нічого не пишемо: інакше та сама розмова опинилась би в двох
        // системах, і менеджер відповідав би двічі.
        if (config('services.inbox.telegram_owner') !== 'crm') {
            Log::info('Telegram webhook проігноровано: канал за зовнішнім Inbox', [
                'account_id' => $account->id,
            ]);

            return response()->json(['ok' => true, 'ignored' => true]);
        }

        if (! $this->verifySecret($request, $account)) {
            Log::warning('Telegram webhook: невірний secret_token', ['account_id' => $account->id]);

            return response()->json(['ok' => false, 'reason' => 'invalid secret'], 403);
        }

        $payload = $request->all();

        try {
            // Бота підключили або відключили від бізнес-акаунта.
            if (isset($payload['business_connection'])) {
                $this->driver->applyConnectionUpdate($account, $payload['business_connection']);

                return response()->json(['ok' => true]);
            }

            if (isset($payload['business_message'])) {
                $this->handleBusinessMessage($account, $payload);

                return response()->json(['ok' => true]);
            }

            // Редагування і видалення поки не переносимо в CRM — фіксуємо факт.
            if (isset($payload['edited_business_message']) || isset($payload['deleted_business_messages'])) {
                Log::info('Telegram business message edited/deleted', ['account_id' => $account->id]);

                return response()->json(['ok' => true]);
            }

            Log::info('Telegram webhook: оновлення без business_*', [
                'account_id' => $account->id,
                'keys'       => array_keys($payload),
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram webhook впав', [
                'account_id' => $account->id,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Повідомлення в бізнес-чаті. Автор — або клієнт, або сам власник акаунта,
     * якщо він відповів зі свого телефону повз CRM.
     */
    protected function handleBusinessMessage(MessengerAccount $account, array $payload): void
    {
        $message = $payload['business_message'];

        if ($this->driver->isFromOwner($account, $message)) {
            $echo = $this->ownEcho($payload);

            if ($echo) {
                $this->handler->handle($account, $echo, Message::DIRECTION_OUTBOUND);
            }

            return;
        }

        $inbound = $this->driver->normalizeInbound($account, $payload);

        if ($inbound) {
            $this->handler->handle($account, $inbound);
        }
    }

    /**
     * Відповідь власника — це наше вихідне повідомлення, але «відправником» для
     * пошуку діалогу лишається клієнт: контакт визначається чатом, а не автором.
     */
    protected function ownEcho(array $payload): ?InboundMessageData
    {
        $msg  = $payload['business_message'];
        $chat = $msg['chat'] ?? [];

        $chatId = (string) ($chat['id'] ?? '');

        if ($chatId === '') {
            return null;
        }

        $displayName = trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: null;

        return new InboundMessageData(
            channel:           MessengerAccount::CHANNEL_TELEGRAM,
            externalChatId:    $chatId,
            externalMessageId: isset($msg['message_id']) ? (string) $msg['message_id'] : null,
            senderExternalId:  $chatId,
            senderUsername:    $chat['username'] ?? null,
            senderDisplayName: $displayName,
            text:              $msg['text'] ?? $msg['caption'] ?? null,
            rawPayload:        $payload,
            sentAt:            isset($msg['date']) ? \Carbon\Carbon::createFromTimestamp((int) $msg['date']) : null,
        );
    }

    /**
     * Telegram повертає наш secret_token у заголовку. Це єдина перевірка
     * автентичності — тіло він не підписує.
     */
    protected function verifySecret(Request $request, MessengerAccount $account): bool
    {
        $expected = $account->credentials['webhook_secret'] ?? null;

        if (! $expected) {
            return false;
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token');

        return $provided !== '' && hash_equals($expected, $provided);
    }
}
