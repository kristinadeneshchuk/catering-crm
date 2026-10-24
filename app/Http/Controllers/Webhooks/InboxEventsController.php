<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboxChat;
use App\Models\InboxEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вебхук подій Inbox → агент «Переписки» (docs/tz-ai-agents-in-crm.md §5).
 * Підпис — HMAC sha256 сирого тіла секретом INBOX_EVENTS_SECRET. Повтори й
 * перестановки можливі → дедуп за event_id. Відповідаємо одразу; чат агент
 * читає пізніше, пачкою, коли клієнт допише.
 */
class InboxEventsController extends Controller
{
    /** Теги Inbox, з якими діалог — не клієнт (events-api.md §5). */
    private const SKIP_TAGS = ['не клієнт', 'курʼєр', "кур'єр", 'працівник', 'постачальник'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('services.ai_agents.inbox_events_secret');
        $raw = $request->getContent();
        $sig = (string) $request->header('X-Inbox-Signature');
        if ($secret === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $sig)) {
            return response()->json(['error' => 'bad signature'], 401);
        }

        $e = json_decode($raw, true);
        if (!is_array($e) || empty($e['event_id']) || empty($e['type'])) return response()->json(['error' => 'bad payload'], 422);

        $d = (array) ($e['data'] ?? []);
        $msg = (array) ($d['message'] ?? []);
        $conv = (array) ($d['conversation'] ?? []);
        $contact = (array) ($d['contact'] ?? []);
        $event = InboxEvent::firstOrCreate(['event_id' => $e['event_id']], [
            'type'            => $e['type'],
            'conversation_id' => $conv['id'] ?? null,
            'message_id'      => $msg['id'] ?? null,
            'direction'       => $msg['direction'] ?? null,
            'sender_type'     => $msg['sender_type'] ?? null,
            'crm_client_id'   => $contact['crm_client_id'] ?? null,
            'contact_kind'    => $contact['kind'] ?? null,
            'project_code'    => $d['account']['project_code'] ?? null,
            'occurred_at'     => !empty($e['occurred_at']) ? Carbon::parse($e['occurred_at']) : now(),
        ]);
        if (!$event->wasRecentlyCreated) return response()->json(['ok' => true, 'duplicate' => true]);

        if ($skip = $this->skipReason($e['type'], $msg, $conv, $contact)) {
            $event->update(['status' => 'skipped', 'note' => $skip]);

            return response()->json(['ok' => true]);
        }

        $event->update(['status' => 'queued']);
        // Пачка: клієнт часто пише кількома повідомленнями — читаємо чат, коли затихне.
        ProcessInboxChat::dispatch((int) $conv['id'])->delay(now()->addMinutes((int) config('services.ai_agents.chats.batch_minutes', 10)));

        return response()->json(['ok' => true]);
    }

    private function skipReason(string $type, array $msg, array $conv, array $contact): ?string
    {
        if (!config('services.ai_agents.chats.enabled')) return 'агент вимкнено';
        if ($type !== 'message.received') return 'не вхідне повідомлення';
        if (($msg['sender_type'] ?? 'client') !== 'client') return 'не від клієнта';
        if (($contact['kind'] ?? 'client') !== 'client') return 'контакт: ' . $contact['kind'];
        if (($conv['chat_type'] ?? 'private') !== 'private') return 'груповий чат';
        if (array_intersect((array) ($conv['tags'] ?? []), self::SKIP_TAGS)) return 'тег: не клієнт';
        if (empty($conv['id'])) return 'без діалогу';

        return null;
    }
}
