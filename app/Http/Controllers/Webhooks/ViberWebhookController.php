<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\MessengerAccount;
use App\Services\Messenger\InboundMessageHandler;
use App\Services\Messenger\Viber\ViberChannelDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ViberWebhookController extends Controller
{
    public function __construct(
        protected ViberChannelDriver  $driver,
        protected InboundMessageHandler $handler,
    ) {
    }

    /**
     * Viber б'є на /webhooks/viber/{account}.
     * Перевіряємо підпис X-Viber-Content-Signature, нормалізуємо подію, передаємо handler'у.
     * Відповідаємо завжди 200 — інакше Viber буде ретраїти.
     */
    public function handle(Request $request, MessengerAccount $account)
    {
        if ($account->channel !== MessengerAccount::CHANNEL_VIBER) {
            return response()->json(['ok' => false, 'reason' => 'wrong channel'], 200);
        }

        $token = $account->credentials['auth_token'] ?? null;
        if (! $token) {
            Log::warning('Viber webhook: account has no auth_token', ['account_id' => $account->id]);
            return response()->json(['ok' => true]);
        }

        $rawBody = $request->getContent();
        $signature = $request->header('X-Viber-Content-Signature');

        if (! $this->verifySignature($rawBody, (string) $signature, $token)) {
            Log::warning('Viber webhook: invalid signature', ['account_id' => $account->id]);
            return response()->json(['ok' => false, 'reason' => 'invalid signature'], 403);
        }

        $payload = $request->all();
        $event   = $payload['event'] ?? null;

        try {
            switch ($event) {
                case 'message':
                    $inbound = $this->driver->normalizeInbound($account, $payload);
                    if ($inbound) {
                        $this->handler->handle($account, $inbound);
                    }
                    break;

                case 'delivered':
                case 'seen':
                case 'failed':
                    $this->driver->applyDeliveryUpdate($account, $payload);
                    break;

                case 'subscribed':
                case 'conversation_started':
                case 'unsubscribed':
                case 'webhook':
                    // Нічого критичного — просто фіксуємо у логи
                    Log::info('Viber webhook event', ['event' => $event, 'account_id' => $account->id]);
                    break;

                default:
                    Log::info('Viber webhook unknown event', ['event' => $event, 'account_id' => $account->id]);
            }
        } catch (\Throwable $e) {
            // Не повертаємо 500, бо Viber почне ретраїти. Логуємо і ковтаємо.
            Log::error('Viber webhook handler crashed', [
                'account_id' => $account->id,
                'event'      => $event,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Viber підписує тіло запиту HMAC-SHA256 з ключем = auth_token.
     */
    protected function verifySignature(string $rawBody, string $signature, string $token): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $token);

        return hash_equals($expected, $signature);
    }
}
