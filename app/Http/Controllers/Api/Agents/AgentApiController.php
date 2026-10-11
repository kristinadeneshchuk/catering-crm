<?php

namespace App\Http\Controllers\Api\Agents;

use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\AiAgentMemory;
use App\Models\AiAgentReport;
use App\Models\AiAgentTask;
use App\Models\AiProposal;
use App\Services\Agents\ProposalDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API для ШІ-агентів. Агент: читає свої правила й задачі, подає пропозиції,
 * дивиться, що з ними сталося, пише звіти, тримає памʼять.
 */
class AgentApiController extends Controller
{
    private function agent(Request $r): AiAgent
    {
        return $r->attributes->get('agent');
    }

    /** Хто я, правила власника, що мені можна. */
    public function me(Request $r): JsonResponse
    {
        $a = $this->agent($r);

        return response()->json([
            'slug' => $a->slug, 'name' => $a->name, 'role' => $a->role,
            'instructions' => $a->instructions, 'allowed_types' => $a->allowed_types,
            'proposal_types' => AiProposal::TYPES,
        ]);
    }

    public function tasks(Request $r): JsonResponse
    {
        $q = $this->agent($r)->tasks()->orderBy('id');
        $q->whereIn('status', $r->filled('status') ? explode(',', $r->query('status')) : ['new', 'in_progress']);

        return response()->json($q->get(['id', 'title', 'details', 'payload', 'status', 'due_at', 'created_at']));
    }

    public function updateTask(Request $r, int $task): JsonResponse
    {
        $t = $this->agent($r)->tasks()->findOrFail($task);
        $data = $r->validate(['status' => 'required|in:in_progress,done,failed', 'result' => 'nullable|string']);

        $t->fill($data);
        if ($data['status'] === 'in_progress' && !$t->started_at) $t->started_at = now();
        if (in_array($data['status'], ['done', 'failed'], true)) $t->finished_at = now();
        $t->save();

        return response()->json(['ok' => true, 'task' => $t->only(['id', 'status'])]);
    }

    public function propose(Request $r, ProposalDesk $desk): JsonResponse
    {
        $data = $r->validate([
            'type' => 'required|string', 'title' => 'nullable|string|max:255', 'summary' => 'nullable|string',
            'task_id' => 'nullable|integer', 'payload' => 'required|array',
            'files' => 'nullable|array', 'files.*.name' => 'required|string', 'files.*.base64' => 'required|string', 'files.*.mime' => 'nullable|string',
        ]);
        if (!empty($data['task_id']) && !$this->agent($r)->tasks()->whereKey($data['task_id'])->exists()) {
            return response()->json(['errors' => ['task_id: не ваша задача']], 422);
        }

        $res = $desk->submit($this->agent($r), $data);
        if ($res['errors']) return response()->json(['errors' => $res['errors']], 422);

        return response()->json(['id' => $res['proposal']->id, 'status' => 'pending', 'warnings' => $res['warnings'] ?? []], 201);
    }

    /** Що сталося з моїми пропозиціями: погоджено/правки/відхилено + коментар. */
    public function proposals(Request $r): JsonResponse
    {
        $q = $this->agent($r)->proposals()->latest('id')->limit((int) $r->query('limit', 50));
        if ($r->filled('status')) $q->whereIn('status', explode(',', $r->query('status')));
        if ($r->filled('since')) $q->where('updated_at', '>=', $r->query('since'));

        return response()->json($q->get(['id', 'type', 'title', 'status', 'review_comment', 'reviewed_at', 'applied_at', 'apply_result', 'order_id', 'client_id', 'created_at']));
    }

    public function report(Request $r): JsonResponse
    {
        $data = $r->validate([
            'title' => 'required|string|max:255', 'body' => 'nullable|string',
            'level' => 'nullable|in:info,warn,error', 'task_id' => 'nullable|integer',
        ]);
        $agent = $this->agent($r);
        $report = AiAgentReport::create([
            'ai_agent_id' => $agent->id,
            'ai_agent_task_id' => $agent->tasks()->whereKey($data['task_id'] ?? 0)->value('id'),
            'title' => $data['title'], 'body' => $data['body'] ?? null, 'level' => $data['level'] ?? 'info',
        ]);

        return response()->json(['id' => $report->id], 201);
    }

    public function memory(Request $r): JsonResponse
    {
        $q = $this->agent($r)->memory();
        if ($r->filled('prefix')) $q->where('key', 'like', $r->query('prefix') . '%');

        return response()->json($q->orderBy('key')->get(['key', 'value', 'updated_at']));
    }

    public function remember(Request $r, string $key): JsonResponse
    {
        $data = $r->validate(['value' => 'present']);
        $m = AiAgentMemory::updateOrCreate(
            ['ai_agent_id' => $this->agent($r)->id, 'key' => $key],
            ['value' => $data['value']],
        );

        return response()->json(['key' => $m->key, 'updated_at' => $m->updated_at]);
    }

    public function forget(Request $r, string $key): JsonResponse
    {
        $this->agent($r)->memory()->where('key', $key)->delete();

        return response()->json(['ok' => true]);
    }

    // --- чат --------------------------------------------------------------

    /** Написати людям (як у переписці з сесією): текст + файли (PDF, фото). */
    public function say(Request $r, \App\Services\Agents\AgentChat $chat): JsonResponse
    {
        $data = $r->validate([
            'text' => 'required_without:files|nullable|string|max:4000',
            'to' => 'nullable|string',
            'files' => 'nullable|array', 'files.*.name' => 'required|string', 'files.*.base64' => 'required|string', 'files.*.mime' => 'nullable|string',
        ]);
        if ($errors = $chat->validateFiles((array) ($data['files'] ?? []))) return response()->json(['errors' => $errors], 422);

        $to = null;
        if (!empty($data['to'])) {
            $to = AiAgent::where('slug', $data['to'])->where('is_active', true)->first();
            if (!$to) return response()->json(['errors' => ["to: агента «{$data['to']}» немає"]], 422);
        }
        $msg = $chat->fromAgent($this->agent($r), (string) ($data['text'] ?? ''), (array) ($data['files'] ?? []), null, $to);

        return response()->json(['id' => $msg->id], 201);
    }

    /** Розмова: відповіді людей і події (погоджено/відхилено). ?after_id= — лише нове. */
    public function messages(Request $r): JsonResponse
    {
        $me = $this->agent($r);
        // Моя розмова з людьми + те, що інші агенти написали мені.
        $q = \App\Models\AiMessage::query()->with(['user:id,name', 'agent:id,slug', 'toAgent:id,slug'])
            ->where(fn ($w) => $w->where(fn ($o) => $o->where('ai_agent_id', $me->id)->whereNull('to_agent_id'))
                ->orWhere('to_agent_id', $me->id)
                ->orWhere(fn ($o) => $o->where('ai_agent_id', $me->id)->whereNotNull('to_agent_id')))
            ->orderBy('id');
        if ($r->filled('after_id')) $q->where('id', '>', (int) $r->query('after_id'));
        if ($r->boolean('only_new_for_me')) $q->where(fn ($w) => $w->where('direction', '!=', 'agent')->orWhere('to_agent_id', $me->id));

        return response()->json($q->limit(200)->get()->map(fn ($m) => [
            'id' => $m->id, 'direction' => $m->direction,
            'from' => $m->direction === 'agent' ? $m->agent?->slug : $m->user?->name,
            'to' => $m->toAgent?->slug, 'text' => $m->text,
            'files' => collect((array) $m->attachments)->pluck('name'), 'proposal_id' => $m->ai_proposal_id,
            'created_at' => $m->created_at->toIso8601String(),
        ]));
    }
}
