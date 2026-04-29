<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Messenger\ChannelDriverManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Черга-обгортка для відправки одного outbound-повідомлення.
 * Знаходить потрібний драйвер за каналом діалогу і викликає send().
 * При помилці — ставить status=failed і записує текст помилки.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public readonly int $messageId,
    ) {
    }

    public function handle(ChannelDriverManager $drivers): void
    {
        $message = Message::with('conversation.messengerAccount')->find($this->messageId);

        if (! $message || ! $message->conversation || ! $message->conversation->messengerAccount) {
            Log::warning('SendOutboundMessage: message or account missing', ['id' => $this->messageId]);
            return;
        }

        if ($message->status !== Message::STATUS_PENDING) {
            // Або вже відправили, або вже failed і retry був ручний — не дублюємо
            return;
        }

        try {
            $driver = $drivers->for($message->conversation->messengerAccount);
            $driver->send($message);
        } catch (Throwable $e) {
            Log::error('SendOutboundMessage: driver send failed', [
                'message_id' => $message->id,
                'channel'    => $message->conversation->channel,
                'error'      => $e->getMessage(),
            ]);

            $message->update([
                'status'        => Message::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        Message::whereKey($this->messageId)->update([
            'status'        => Message::STATUS_FAILED,
            'error_message' => mb_substr($e->getMessage(), 0, 1000),
        ]);
    }
}
