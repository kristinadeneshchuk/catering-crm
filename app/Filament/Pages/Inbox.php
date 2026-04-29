<?php

namespace App\Filament\Pages;

use App\Jobs\SendOutboundMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessengerAccount;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class Inbox extends Page
{
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Чати';
    protected static ?string $title           = 'Чати';
    protected static ?string $slug            = 'inbox';
    protected static ?int    $navigationSort  = 1;

    protected static string $view = 'filament.pages.inbox';

    // === Стан компонента (Livewire properties) ===

    public ?int $selectedConversationId = null;

    /** @var string all|unread|mine|unassigned|closed */
    public string $filter = 'all';

    /** @var string|null null=всі, інакше telegram|instagram|viber */
    public ?string $channelFilter = null;

    public string $search = '';

    /** Текст у полі вводу */
    public string $messageDraft = '';

    // === Доступ ===

    public static function canAccess(): bool
    {
        return auth()->check()
            && in_array(auth()->user()->role, ['admin', 'manager'], true);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    // Echo-listener: коли через Pusher прилетає 'MessageReceived' на канал 'messenger-inbox' —
    // Livewire оновлює компонент. Текст повідомлення підтягуємо з БД, не з payload events.
    public function getListeners(): array
    {
        return [
            'echo:messenger-inbox,MessageReceived' => '$refresh',
        ];
    }

    // === Дії ===

    public function selectConversation(int $id): void
    {
        $this->selectedConversationId = $id;
        $this->messageDraft = '';

        // Скидаємо лічильник непрочитаних
        Conversation::whereKey($id)->update(['unread_count' => 0]);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
    }

    public function setChannelFilter(?string $channel): void
    {
        $this->channelFilter = $channel;
    }

    public function assignToMe(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)
            ->update(['assigned_user_id' => auth()->id()]);
    }

    public function closeConversation(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)->update([
            'status'    => Conversation::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function reopenConversation(): void
    {
        if (! $this->selectedConversationId) {
            return;
        }

        Conversation::whereKey($this->selectedConversationId)->update([
            'status'    => Conversation::STATUS_OPEN,
            'closed_at' => null,
        ]);
    }

    /**
     * Створює outbound-повідомлення зі статусом pending і кидає в чергу.
     * SendOutboundMessage job знайде драйвер каналу і реально відправить у Viber/IG/Telegram.
     */
    public function sendMessage(): void
    {
        $text = trim($this->messageDraft);

        if ($text === '' || ! $this->selectedConversationId) {
            return;
        }

        $conversation = Conversation::find($this->selectedConversationId);

        if (! $conversation) {
            return;
        }

        $message = DB::transaction(function () use ($conversation, $text) {
            $msg = Message::create([
                'conversation_id' => $conversation->id,
                'direction'       => Message::DIRECTION_OUTBOUND,
                'sender_type'     => Message::SENDER_USER,
                'sender_user_id'  => auth()->id(),
                'type'            => Message::TYPE_TEXT,
                'text'            => $text,
                'status'          => Message::STATUS_PENDING,
            ]);

            $conversation->update([
                'last_message_at'      => now(),
                'last_message_preview' => mb_substr($text, 0, 200),
            ]);

            return $msg;
        });

        // Відправка через драйвер каналу — асинхронно, щоб UI не чекав HTTP до Viber/Meta
        SendOutboundMessage::dispatch($message->id);

        $this->messageDraft = '';
    }

    // === Дані для view ===

    public function getViewData(): array
    {
        return [
            'conversations' => $this->loadConversations(),
            'selected'      => $this->loadSelected(),
            'messages'      => $this->loadMessages(),
            'channelStats'  => $this->channelStats(),
        ];
    }

    protected function loadConversations()
    {
        $query = Conversation::query()
            ->with([
                'clientChannel.client',
                'messengerAccount',
                'assignedUser',
            ]);

        if ($this->channelFilter) {
            $query->where('channel', $this->channelFilter);
        }

        match ($this->filter) {
            'unread'     => $query->where('unread_count', '>', 0)->where('status', Conversation::STATUS_OPEN),
            'mine'       => $query->where('assigned_user_id', auth()->id())->where('status', Conversation::STATUS_OPEN),
            'unassigned' => $query->whereNull('assigned_user_id')->where('status', Conversation::STATUS_OPEN),
            'closed'     => $query->where('status', Conversation::STATUS_CLOSED),
            default      => $query->where('status', Conversation::STATUS_OPEN),
        };

        if ($this->search !== '') {
            $term = '%' . $this->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('last_message_preview', 'like', $term)
                  ->orWhereHas('clientChannel', fn ($cc) => $cc
                      ->where('display_name', 'like', $term)
                      ->orWhere('username', 'like', $term))
                  ->orWhereHas('clientChannel.client', fn ($c) => $c
                      ->where('name', 'like', $term)
                      ->orWhere('phone', 'like', $term));
            });
        }

        return $query
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();
    }

    protected function loadSelected(): ?Conversation
    {
        if (! $this->selectedConversationId) {
            return null;
        }

        return Conversation::with([
            'clientChannel.client.orders' => fn ($q) => $q->latest('id')->limit(3),
            'messengerAccount',
            'assignedUser',
        ])->find($this->selectedConversationId);
    }

    protected function loadMessages()
    {
        if (! $this->selectedConversationId) {
            return collect();
        }

        return Message::with(['attachments', 'senderUser', 'replyTo'])
            ->where('conversation_id', $this->selectedConversationId)
            ->orderBy('created_at')
            ->limit(200)
            ->get();
    }

    /** @return array<string, int> непрочитані по кожному каналу */
    protected function channelStats(): array
    {
        return Conversation::query()
            ->where('status', Conversation::STATUS_OPEN)
            ->where('unread_count', '>', 0)
            ->groupBy('channel')
            ->selectRaw('channel, COUNT(*) as cnt')
            ->pluck('cnt', 'channel')
            ->toArray();
    }
}
