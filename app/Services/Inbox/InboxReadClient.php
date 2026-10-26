<?php

namespace App\Services\Inbox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * Читання чатів з Inbox для звітів. Лише GET під read-only токеном: цим
 * шляхом ні написати клієнту, ні щось змінити в Inbox не можна.
 *
 * Контракт: unified-inbox/docs/events-api.md §4.
 *
 * Inbox пускає 60 запитів на весь /api/agent/* за календарну хвилину, а їх
 * ділять звіти, звірка й агенти в різних процесах — тому спільний дросель
 * у кеші за тією ж хвилиною (services.inbox.agent_rpm) і повтор 429 після
 * Retry-After, без нього — з початком наступної хвилини. Не дочекались — InboxRateLimited.
 */
class InboxReadClient
{
    private const LIMITER = 'inbox-agent-api';
    private const RETRIES_ON_429 = 2;

    public function configured(): bool
    {
        return filled(config('services.inbox.agent_token')) && filled(config('services.inbox.agent_base_url'));
    }

    /**
     * Діалоги, що змінювались від дати (з пагінацією Inbox).
     *
     * @return Collection<int, array> кожен елемент: {conversation, account, contact}
     */
    public function conversationsUpdatedSince(\DateTimeInterface $since, int $max = 2000): Collection
    {
        $all   = collect();
        $query = ['updated_since' => $since->format('Y-m-d\TH:i:s\Z'), 'limit' => 200];

        while ($all->count() < $max) {
            $page = $this->get('/api/agent/conversations', $query);

            $all = $all->concat($page['conversations'] ?? []);

            if (empty($page['has_more']) || empty($page['next'])) {
                break;
            }

            $query = $page['next'] + ['limit' => 200];
        }

        return $all;
    }

    /**
     * Повідомлення діалогу від старих до нових, без нотаток менеджерів.
     *
     * @return Collection<int, array>
     */
    public function messages(int $conversationId, int $limit = 100): Collection
    {
        $page = $this->get("/api/agent/conversations/{$conversationId}/messages", ['limit' => min(100, $limit)]);

        return collect($page['messages'] ?? [])->reject(fn (array $m) => ! empty($m['is_note']))->values();
    }

    /**
     * Чати Inbox, привʼязані до клієнта CRM.
     *
     * @return array<int, array> [{conversation, account, contact}]
     */
    public function conversationsForClient(int $clientId): array
    {
        return (array) ($this->get('/api/agent/conversations', ['crm_client_id' => $clientId, 'limit' => 20])['conversations'] ?? []);
    }

    /** Діалог: conversation, account, contact, crm_orders, link. */
    public function conversation(int $conversationId): array
    {
        return $this->get("/api/agent/conversations/{$conversationId}", []);
    }

    /**
     * Сторінка повідомлень разом із нотатками менеджерів (is_note) — для агента «Переписки».
     *
     * @return array{messages: array, has_more: bool}
     */
    public function page(int $conversationId, int $limit = 60, ?int $before = null): array
    {
        $page = $this->get("/api/agent/conversations/{$conversationId}/messages", array_filter(['limit' => min(100, $limit), 'before' => $before]));

        return ['messages' => (array) ($page['messages'] ?? []), 'has_more' => (bool) ($page['has_more'] ?? false)];
    }

    private function get(string $path, array $query): array
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            $response = Http::withToken((string) config('services.inbox.agent_token'))
                ->acceptJson()
                ->timeout(20)
                ->retry(2, 500, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->get(rtrim((string) config('services.inbox.agent_base_url'), '/').$path, $query);

            if ($response->status() !== 429) {
                $response->throw();

                return (array) $response->json();
            }

            $wait = $this->retryAfter($response);
            if ($attempt >= self::RETRIES_ON_429 || $wait > $this->maxWait()) {
                throw new InboxRateLimited($wait);
            }
            Sleep::for($wait)->seconds();
        }
    }

    /** Не більше agent_rpm запитів за календарну хвилину на всю CRM; над лімітом — чекаємо наступну. */
    private function throttle(): void
    {
        $rpm = (int) config('services.inbox.agent_rpm', 50);
        if ($rpm <= 0) return;

        $waited = 0;
        while (true) {
            $key = self::LIMITER.':'.intdiv(now()->getTimestamp(), 60);
            Cache::add($key, 0, 120);
            if (Cache::increment($key) <= $rpm) return;

            $wait = $this->untilNextMinute();
            if ($waited + $wait > $this->maxWait()) {
                throw new InboxRateLimited($wait);
            }
            Sleep::for($wait)->seconds();
            $waited += $wait;
        }
    }

    private function retryAfter(Response $response): int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : $this->untilNextMinute();
    }

    private function untilNextMinute(): int
    {
        return 61 - now()->getTimestamp() % 60;
    }

    /** Фонові команди й черга можуть почекати хвилину, сторінка в адмінці — ні. */
    private function maxWait(): int
    {
        return app()->runningInConsole() ? 90 : 5;
    }
}
