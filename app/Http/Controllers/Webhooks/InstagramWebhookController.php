<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\MessengerAccount;
use App\Services\Messenger\InboundMessageHandler;
use App\Services\Messenger\Instagram\InstagramChannelDriver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class InstagramWebhookController extends Controller
{
    public function __construct(
        protected InstagramChannelDriver $driver,
        protected InboundMessageHandler  $handler,
    ) {
    }

    /**
     * GET /webhooks/instagram — Meta перевіряє webhook через challenge.
     * Очікує, що ми повернемо hub.challenge, якщо hub.verify_token збігається.
     */
    public function verify(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $expected = config('services.meta.webhook_verify_token');

        if ($mode === 'subscribe' && $token === $expected && $challenge) {
            return response($challenge, 200);
        }

        Log::warning('Instagram webhook verify failed', [
            'mode'  => $mode,
            'token' => $token ? 'provided' : 'missing',
        ]);

        return response('Forbidden', 403);
    }

    /**
     * POST /webhooks/instagram — нові події (повідомлення, прочитання, реакції).
     * Перевіряємо підпис X-Hub-Signature-256 з App Secret.
     */
    public function handle(Request $request)
    {
        $rawBody   = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256');

        if (! $this->verifySignature($rawBody, $signature)) {
            Log::warning('Instagram webhook: invalid signature');
            return response('Forbidden', 403);
        }

        $payload = $request->all();

        if (($payload['object'] ?? null) !== 'instagram') {
            // Може прийти 'page' або інший тип — нас цікавить тільки instagram messaging
            return response()->json(['ok' => true]);
        }

        try {
            foreach ($payload['entry'] ?? [] as $entry) {
                $pageId = $entry['id'] ?? null;
                if (! $pageId) continue;

                // Тестові webhooks з Meta Dashboard надсилаються з entry.id="0".
                // Підпис у них валідний (підписаний нашим App Secret), тому це безпечно
                // мапити на першу активну IG account — щоб дашбордовий «Тестировать»
                // створював тестове повідомлення в CRM /admin/inbox.
                $account = $pageId === '0'
                    ? MessengerAccount::where('channel', MessengerAccount::CHANNEL_INSTAGRAM)
                        ->where('status', MessengerAccount::STATUS_ACTIVE)
                        ->first()
                    : MessengerAccount::where('channel', MessengerAccount::CHANNEL_INSTAGRAM)
                        ->where('external_account_id', $pageId)
                        ->first();

                if (! $account) {
                    Log::info('IG webhook: no account for page_id', ['page_id' => $pageId]);
                    continue;
                }

                // Реальні DM приходять у форматі entry[].messaging[]
                foreach ($entry['messaging'] ?? [] as $event) {
                    $inbound = $this->driver->normalizeInbound($account, $event);
                    if ($inbound) {
                        $this->handler->handle($account, $inbound);
                    }
                }

                // Тестові події з Meta Dashboard ("Тестировать" біля webhook field)
                // приходять у форматі entry[].changes[] — конвертуємо в messaging-event
                // і обробляємо тим самим шляхом.
                foreach ($entry['changes'] ?? [] as $change) {
                    if (($change['field'] ?? null) !== 'messages') continue;

                    $value = $change['value'] ?? [];
                    if (empty($value['message'])) continue;

                    $synthetic = [
                        'sender'    => $value['sender']    ?? [],
                        'recipient' => $value['recipient'] ?? [],
                        'timestamp' => isset($value['timestamp']) ? ((int) $value['timestamp']) * 1000 : null,
                        'message'   => $value['message']   ?? [],
                    ];

                    $inbound = $this->driver->normalizeInbound($account, $synthetic);
                    if ($inbound) {
                        $this->handler->handle($account, $inbound);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Не повертаємо 500, інакше Meta почне ретраїти і дисейблить webhook.
            Log::error('Instagram webhook handler crashed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    protected function verifySignature(string $rawBody, string $signature): bool
    {
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        // У новій Instagram Login API webhook підписується Instagram App Secret
        // (з product Instagram), а не загальним App Secret самого Meta App.
        $appSecret = config('services.meta.instagram.app_secret');
        if (! $appSecret) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signature);
    }
}
