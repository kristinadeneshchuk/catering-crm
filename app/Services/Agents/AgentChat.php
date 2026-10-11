<?php

namespace App\Services\Agents;

use App\Models\AiAgent;
use App\Models\AiMessage;
use App\Models\AiProposal;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Чат з агентом — як переписка з сесією: агент пише й кидає файли, людина
 * відповідає (у CRM або реплаєм у Telegram), пропозиції стоять у розмові
 * карткою з кнопками.
 */
class AgentChat
{
    public const MAX_FILES = 10;
    public const MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(private TelegramService $telegram) {}

    /**
     * @param  array<int, array{name: string, mime?: string, base64?: string, content?: string}>  $files
     */
    public function fromAgent(AiAgent $agent, string $text, array $files = [], ?AiProposal $proposal = null, ?AiAgent $to = null): AiMessage
    {
        $msg = AiMessage::create([
            'ai_agent_id'    => $agent->id,
            'to_agent_id'    => $to?->id,
            'direction'      => 'agent',
            'text'           => $text,
            'attachments'    => $this->store($agent, $files),
            'ai_proposal_id' => $proposal?->id,
        ]);

        // Розмова агентів між собою — лише в CRM, людей у Telegram не смикаємо.
        if (!$to) $this->toTelegram($msg, $proposal);

        return $msg;
    }

    /** Відповідь людини (з CRM чи реплаєм у Telegram) — агент читає її по API. */
    public function fromHuman(AiAgent $agent, ?User $user, string $text, array $files = []): AiMessage
    {
        return AiMessage::create([
            'ai_agent_id' => $agent->id,
            'direction'   => 'human',
            'user_id'     => $user?->id,
            'text'        => $text,
            'attachments' => $this->store($agent, $files),
        ]);
    }

    /** Подія в розмові: «погоджено», «відхилено» — щоб агент і люди бачили хронологію. */
    public function system(AiAgent $agent, string $text, ?AiProposal $proposal = null, ?User $user = null): AiMessage
    {
        return AiMessage::create([
            'ai_agent_id'    => $agent->id,
            'direction'      => 'system',
            'user_id'        => $user?->id,
            'text'           => $text,
            'ai_proposal_id' => $proposal?->id,
        ]);
    }

    /** Реплай у Telegram на повідомлення агента → відповідь у чат. */
    public function replyFromTelegram(int $replyToMessageId, string $chatId, ?User $user, string $text): ?AiMessage
    {
        $original = AiMessage::query()
            ->where('direction', 'agent')->whereNull('to_agent_id')
            ->latest('id')->limit(500)->get()
            ->first(fn (AiMessage $m) => collect((array) $m->telegram_messages)
                ->contains(fn ($t) => (string) $t['chat_id'] === $chatId && (int) $t['message_id'] === $replyToMessageId));

        return $original ? $this->fromHuman($original->agent, $user, $text) : null;
    }

    public function validateFiles(array $files): array
    {
        $e = [];
        if (count($files) > self::MAX_FILES) $e[] = 'attachments: не більше ' . self::MAX_FILES;
        foreach ($files as $i => $f) {
            if (blank($f['name'] ?? null)) $e[] = "attachments[$i].name: порожньо";
            $bin = base64_decode((string) ($f['base64'] ?? ''), true);
            if ($bin === false || $bin === '') $e[] = "attachments[$i].base64: не читається";
            elseif (strlen($bin) > self::MAX_BYTES) $e[] = "attachments[$i]: більше 20 МБ";
        }

        return $e;
    }

    private function store(AiAgent $agent, array $files): array
    {
        $dir = 'ai/chat/' . $agent->slug . '/' . now()->format('Y-m') . '/' . Str::uuid();
        $out = [];
        foreach (array_values($files) as $i => $f) {
            $content = $f['content'] ?? base64_decode((string) ($f['base64'] ?? ''), true);
            if ($content === false || $content === null) throw new InvalidArgumentException("Файл «{$f['name']}» не читається");
            $ext = pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'bin';
            $path = "{$dir}/{$i}_" . (Str::slug(pathinfo($f['name'], PATHINFO_FILENAME)) ?: 'file') . ".{$ext}";
            Storage::disk('local')->put($path, $content);
            $out[] = ['name' => $f['name'], 'mime' => $f['mime'] ?? null, 'path' => $path, 'size' => strlen($content)];
        }

        return $out;
    }

    private function toTelegram(AiMessage $msg, ?AiProposal $proposal): void
    {
        $desk = app(ProposalDesk::class);
        $keyboard = $proposal && $proposal->isPending() ? [[
            ['text' => '✅ Погодити', 'callback_data' => ProposalDesk::CALLBACK . ":ok:{$proposal->id}"],
            ['text' => '❌ Відхилити', 'callback_data' => ProposalDesk::CALLBACK . ":no:{$proposal->id}"],
        ], [
            ['text' => '🔎 Деталі в CRM', 'url' => $desk->url($proposal)],
        ]] : null;

        $head = "🤖 <b>" . e($msg->agent->name) . "</b>\n";
        $foot = $keyboard ? '' : "\n\n<i>Відповісти агенту — реплаєм на це повідомлення.</i>";
        $sent = [];
        $texts = [];   // лише текстові — їх редагуємо після погодження
        foreach (array_filter($desk->approvers()) as $tg) {
            try {
                $id = $this->telegram->sendMessage((string) $tg, $head . e((string) $msg->text) . $foot, $keyboard);
                if (!$id) continue;
                $sent[] = $texts[] = ['chat_id' => (string) $tg, 'message_id' => $id];
                foreach ((array) $msg->attachments as $f) {
                    $fid = $this->telegram->sendDocument((string) $tg, (string) Storage::disk('local')->get($f['path']), $f['name'], null, $id);
                    if ($fid) $sent[] = ['chat_id' => (string) $tg, 'message_id' => $fid];
                }
            } catch (Throwable $e) {
                Log::warning('ai chat → telegram failed', ['chat' => $tg, 'error' => $e->getMessage()]);
            }
        }

        $msg->forceFill(['telegram_messages' => $sent])->save();
        $proposal?->forceFill(['telegram_messages' => $texts])->save();
    }
}
