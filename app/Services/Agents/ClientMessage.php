<?php

namespace App\Services\Agents;

use App\Models\AiProposal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Пропозиція «повідомлення клієнту»: агент пропонує текст (і вкладення) →
 * погоджувач у CRM → CRM підписаним запитом передає в Inbox, і той одразу шле.
 * Контракт узгоджено з сесією Inbox 11.10.2026.
 *
 * payload: conversation_id, crm_client_id?, account, text, attachments[{kind,name,mime,base64}],
 *          based_on_message_id?, preview?
 */
class ClientMessage
{
    public const MAX_TEXT = 4000;
    public const MAX_FILES = 10;
    public const MAX_BYTES = 10 * 1024 * 1024;
    /** Увесь запит до Inbox (Caddy ріже на 35 МБ; base64 — ×4/3). */
    public const MAX_TOTAL_BYTES = 25 * 1024 * 1024;
    public const PHOTO_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function validate(array $p): array
    {
        $e = [];
        if ((int) ($p['conversation_id'] ?? 0) <= 0) $e[] = 'conversation_id: потрібен id розмови Inbox';
        if (!isset($p['based_on_message_id']) || !is_numeric($p['based_on_message_id']) || (int) $p['based_on_message_id'] < 0) {
            $e[] = 'based_on_message_id: обовʼязковий (0 — якщо клієнт ще не писав)';
        }
        $text = trim((string) ($p['text'] ?? ''));
        if ($text === '' && empty($p['attachments'])) $e[] = 'text: порожньо';
        if (mb_strlen($text) > self::MAX_TEXT) $e[] = 'text: довше ' . self::MAX_TEXT . ' символів';
        if (preg_match('/\[[^\]\n]{1,60}\]/u', $text)) $e[] = 'text: є незаповнена [заготовка]';

        $files = (array) ($p['attachments'] ?? []);
        if (count($files) > self::MAX_FILES) $e[] = 'attachments: не більше ' . self::MAX_FILES;
        $total = 0;
        foreach ($files as $i => $f) {
            if (!in_array($f['kind'] ?? null, ['photo', 'document'], true)) $e[] = "attachments[$i].kind: photo або document";
            if (($f['kind'] ?? null) === 'photo' && !in_array(strtolower((string) ($f['mime'] ?? '')), self::PHOTO_MIMES, true)) {
                $e[] = "attachments[$i]: фото лише PNG, JPEG або WebP (інше — як document)";
            }
            if (blank($f['name'] ?? null)) $e[] = "attachments[$i].name: порожньо";
            $bin = isset($f['base64']) ? base64_decode((string) $f['base64'], true) : (isset($f['path']) ? 'stored' : false);
            if ($bin === false) $e[] = "attachments[$i]: base64 не читається";
            elseif (strlen($bin) > self::MAX_BYTES) $e[] = "attachments[$i]: більше 10 МБ";
            if (is_string($bin) && $bin !== 'stored') $total += strlen($bin);
        }
        if ($total > self::MAX_TOTAL_BYTES) $e[] = 'attachments: разом більше 25 МБ — Inbox не прийме запит';

        return $e;
    }

    /** Вкладення — у файли, не в базу: у payload лишається шлях. */
    public function prepare(array $p): array
    {
        $dir = 'ai/proposals/' . now()->format('Y-m') . '/' . Str::uuid();
        foreach ((array) ($p['attachments'] ?? []) as $i => $f) {
            if (!isset($f['base64'])) continue;
            $path = $dir . '/' . $i . '_' . Str::slug(pathinfo($f['name'], PATHINFO_FILENAME)) . '.' . (pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'bin');
            Storage::disk('local')->put($path, base64_decode($f['base64']));
            unset($f['base64']);
            $p['attachments'][$i] = $f + ['path' => $path];
        }

        return $p;
    }

    public function describe(array $p): string
    {
        $files = count($p['attachments'] ?? []);

        return trim(($p['preview'] ?? ($p['account'] ?? 'Клієнт')) . "\n«" . Str::limit(trim((string) ($p['text'] ?? '')), 600) . '»'
            . ($files ? "\n📎 вкладень: {$files}" : ''));
    }

    /** Відправка в Inbox. Успіх — підсумок; відома відмова — ProposalOutcome з потрібним статусом. */
    public function apply(array $p, AiProposal $proposal, bool $force = false): array
    {
        $url = (string) config('services.ai_agents.inbox_send_url');
        $secret = (string) config('services.ai_agents.inbox_send_secret');
        if ($url === '' || $secret === '') {
            throw new ProposalOutcome('failed', 'Відправка в Inbox ще не налаштована (INBOX_CRM_SEND_URL / CRM_SEND_SECRET).');
        }

        $attachments = array_map(function ($f) {
            $out = array_intersect_key($f, array_flip(['kind', 'name', 'mime']));
            $out['base64'] = base64_encode((string) Storage::disk('local')->get($f['path']));
            return $out;
        }, (array) ($p['attachments'] ?? []));

        $reviewer = $proposal->reviewer;
        $body = json_encode(array_merge($p, [
            'attachments'  => $attachments,
            'proposal_id'  => $proposal->id,
            'approved_by'  => ['id' => $reviewer?->id, 'name' => $reviewer?->name],
            'approved_at'  => ($proposal->reviewed_at ?? now())->toIso8601String(),
        ], $force ? ['force' => true] : []), JSON_UNESCAPED_UNICODE);

        $attempt = 0;
        do {
            $attempt++;
            $ts = (string) time();
            $response = Http::timeout(30)
                ->withHeaders([
                    'X-Inbox-Timestamp' => $ts,
                    'X-Inbox-Signature' => 'sha256=' . hash_hmac('sha256', $ts . '.' . $body, $secret),
                    'Idempotency-Key'   => 'proposal-' . $proposal->id,
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } while ($response->status() === 502 && $attempt < 3);

        $json = (array) $response->json();

        return match (true) {
            in_array($response->status(), [200, 201], true) => ['sent' => true, 'message_ids' => $json['message_ids'] ?? [], 'duplicate' => (bool) ($json['duplicate'] ?? false)],
            $response->status() === 409 && ($json['code'] ?? null) === 'stale' => throw new ProposalOutcome('changes_requested',
                'Клієнт написав ще після цього повідомлення — не надіслано. Перегляньте розмову; якщо текст досі доречний, натисніть «Надіслати все одно».', ['code' => 'stale']),
            $response->status() === 409 && ($json['code'] ?? null) === 'locked' => throw new ProposalOutcome('failed',
                'Діалог зараз веде менеджер — не надіслано.', ['code' => 'locked']),
            $response->status() === 422 => throw new ProposalOutcome('failed', 'Inbox відхилив: ' . ($json['message'] ?? json_encode($json['errors'] ?? $json, JSON_UNESCAPED_UNICODE)), ['code' => 'invalid']),
            default => throw new ProposalOutcome('failed', "Inbox відповів {$response->status()}" . ($attempt > 1 ? " (спроб: {$attempt})" : ''), ['code' => 'http_' . $response->status()]),
        };
    }
}
