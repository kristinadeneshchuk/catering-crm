<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Доставка події в Telegram Inbox.
 *
 * У черзі, бо зовнішня система може лежати, а перерахунок балансу клієнта
 * чекати на HTTP не має права — він крутиться всередині збереження замовлення.
 *
 * event_id робимо стабільним (подія + замовлення + статус), щоб повторна
 * доставка після падіння не створила у Inbox другу подію: приймальна сторона
 * може дедуплікувати по ньому.
 */
class SendInboxWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Пʼять спроб із наростаючою паузою — на випадок короткої недоступності. */
    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(
        public string $event,
        public string $eventId,
        public array $payload,
        public string $occurredAt,
    ) {
    }

    public function handle(): void
    {
        $url = (string) config('services.inbox.webhook_url');

        if ($url === '') {
            return;
        }

        $body = [
            'event'       => $this->event,
            'event_id'    => $this->eventId,
            'occurred_at' => $this->occurredAt,
            'data'        => $this->payload,
        ];

        // Кодуємо тіло самі й відправляємо рівно цей рядок. Якщо віддати масив
        // у post(), Laravel закодує його по-своєму (з екранованим юнікодом) — і
        // підпис рахувався б по одному рядку, а летів би інший. Поки в payload
        // сама латиниця, це збігається випадково; перше ж поле з кирилицею
        // мовчки зламало б перевірку на приймальній стороні.
        $json = json_encode($body, JSON_UNESCAPED_UNICODE);

        $request = Http::timeout(10)
            ->acceptJson()
            ->withBody($json, 'application/json');

        // Підпис тіла — щоб приймальна сторона знала, що це справді ми.
        // Перевіряти треба сире тіло, до розбору JSON.
        $secret = (string) config('services.inbox.webhook_secret');
        if ($secret !== '') {
            $request = $request->withHeaders([
                'X-Crm-Signature' => hash_hmac('sha256', $json, $secret),
            ]);
        }

        $response = $request->post($url);

        if ($response->failed()) {
            Log::warning('Inbox webhook відхилено', [
                'event_id' => $this->eventId,
                'status'   => $response->status(),
                'body'     => mb_substr($response->body(), 0, 500),
            ]);

            // Кидаємо далі — черга повторить за розкладом backoff.
            $response->throw();
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('Inbox webhook не доставлено після всіх спроб', [
            'event'    => $this->event,
            'event_id' => $this->eventId,
            'error'    => $e->getMessage(),
        ]);
    }
}
