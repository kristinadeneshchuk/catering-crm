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
        ]);
        if (!empty($data['task_id']) && !$this->agent($r)->tasks()->whereKey($data['task_id'])->exists()) {
            return response()->json(['errors' => ['task_id: не ваша задача']], 422);
        }

        $res = $desk->submit($this->agent($r), $data);
        if ($res['errors']) return response()->json(['errors' => $res['errors']], 422);

        return response()->json(['id' => $res['proposal']->id, 'status' => 'pending'], 201);
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
}
