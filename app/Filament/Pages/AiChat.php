<?php

namespace App\Filament\Pages;

use App\Models\AiAgent;
use App\Models\AiMessage;
use App\Models\AiProposal;
use App\Services\Agents\AgentChat;
use App\Services\Agents\ProposalDesk;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\WithFileUploads;

/** Переписка з ШІ-агентами, як із сесією: повідомлення, файли, пропозиції з кнопками. */
class AiChat extends Page
{
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'ШІ-чат';
    protected static ?string $title = 'ШІ-чат';
    protected static ?string $navigationGroup = 'ШІ';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.ai.chat';

    public ?int $agentId = null;
    public string $draft = '';
    public array $uploads = [];

    public static function canAccess(): bool
    {
        return app(ProposalDesk::class)->mayApprove(auth()->user());
    }

    public function mount(): void
    {
        $this->agentId = request()->integer('agent') ?: AiMessage::latest('id')->value('ai_agent_id') ?: AiAgent::orderBy('id')->value('id');
    }

    public function selectAgent(int $id): void
    {
        $this->agentId = $id;
    }

    public function send(): void
    {
        $agent = AiAgent::find($this->agentId);
        $text = trim($this->draft);
        if (!$agent || ($text === '' && !$this->uploads)) return;

        $files = collect($this->uploads)->map(fn ($f) => [
            'name' => $f->getClientOriginalName(), 'mime' => $f->getMimeType(), 'content' => file_get_contents($f->getRealPath()),
        ])->all();

        app(AgentChat::class)->fromHuman($agent, auth()->user(), $text, $files);
        $this->reset('draft', 'uploads');
    }

    public function approve(int $proposalId): void
    {
        $p = AiProposal::findOrFail($proposalId);
        Notification::make()->title(app(ProposalDesk::class)->approve($p, auth()->user()))->send();
    }

    public function changes(int $proposalId): void
    {
        $text = trim($this->draft);
        if ($text === '') {
            Notification::make()->title('Напишіть правки в полі внизу й натисніть «Правки» ще раз')->warning()->send();
            return;
        }
        Notification::make()->title(app(ProposalDesk::class)->reject(AiProposal::findOrFail($proposalId), auth()->user(), $text, true))->send();
        $this->reset('draft');
    }

    public function reject(int $proposalId): void
    {
        Notification::make()->title(app(ProposalDesk::class)->reject(AiProposal::findOrFail($proposalId), auth()->user(), trim($this->draft) ?: null))->send();
        $this->reset('draft');
    }

    protected function getViewData(): array
    {
        $agents = AiAgent::orderBy('id')->get()->map(fn ($a) => $a->setAttribute('pending', $a->proposals()->where('status', 'pending')->count()));
        $messages = $this->agentId
            ? AiMessage::with(['user:id,name', 'agent:id,name', 'toAgent:id,name', 'proposal'])
                ->where(fn ($w) => $w->where('ai_agent_id', $this->agentId)->orWhere('to_agent_id', $this->agentId))
                ->latest('id')->limit(150)->get()->reverse()->values()
            : collect();

        return ['agents' => $agents, 'messages' => $messages];
    }
}
