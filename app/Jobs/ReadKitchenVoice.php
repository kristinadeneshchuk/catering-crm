<?php

namespace App\Jobs;

use App\Services\Ai\OveruseReader;
use App\Services\Ai\SpeechToText;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Голосове з чату кухні: розпізнати → чернетка списання поза нормою.
 *
 * У чаті бот лише ставить ✅. Усе, що потребує уваги, іде власнику в особисті.
 */
class ReadKitchenVoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private string $path,
        private string $chatId,
        private int $messageId,
    ) {
    }

    public function handle(SpeechToText $speech, OveruseReader $reader, TelegramService $telegram): void
    {
        $transcript = $speech->transcribe($this->path);

        if (! $transcript) {
            $telegram->sendToOwner('🎙 Голосове з кухні не вдалося розпізнати. Попросіть написати текстом.');

            return;
        }

        $document = $reader->fromTranscript($transcript, ['chat' => 'kitchen', 'message_id' => $this->messageId]);

        if (! $document) {
            $telegram->sendToOwner("🎙 З кухні: «{$transcript}»\nНе зрозумів, який це товар — запишіть списання вручну.");

            return;
        }

        $telegram->reactToMessage($this->chatId, $this->messageId, '✅');

        $url = \App\Filament\Resources\StockDocumentResource::getUrl('edit', ['record' => $document]);
        $sum = number_format((float) $document->total_sum, 0, ',', ' ');

        $telegram->sendToOwner(
            "🎙 <b>Списання поза нормою</b> (голосове з кухні)\n"
            .e($document->ai_comment)."\n"
            ."Орієнтовна вартість: {$sum} ₴\n"
            ."Перевірити й провести: {$url}",
        );
    }
}
