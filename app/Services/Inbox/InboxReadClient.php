<?php

namespace App\Services\Inbox;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Читання чатів з Inbox для звітів. Лише GET під read-only токеном: цим
 * шляхом ні написати клієнту, ні щось змінити в Inbox не можна.
 *
 * Контракт: unified-inbox/docs/events-api.md §4.
 */
class InboxReadClient
{
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
        $response = Http::withToken((string) config('services.inbox.agent_token'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500)
            ->get(rtrim((string) config('services.inbox.agent_base_url'), '/').$path, $query);

        $response->throw();

        return (array) $response->json();
    }
}
