<?php

namespace App\Jobs;

use App\Services\Ai\InvoiceReader;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Фото накладної з чату кухні → чернетка надходження + повідомлення власнику.
 *
 * Альбом приходить кількома оновленнями, тому фото збираються в кеші за
 * media_group_id, а job запускається один раз із затримкою.
 */
class ReadKitchenInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        private string $cacheKey,
        private string $chatId,
        private int $messageId,
        private ?string $notifyChatId = null,
    ) {
    }

    public function handle(InvoiceReader $reader, TelegramService $telegram, \App\Services\Ai\SupplierPicker $suppliers): void
    {
        $paths = array_values(array_unique(Cache::pull($this->cacheKey, [])));

        if ($paths === []) {
            return;
        }

        $document = $reader->fromPhotos($paths, ['chat' => 'kitchen', 'message_id' => $this->messageId]);

        if (! $document) {
            $miss = '🧾 Накладну не вдалося зчитати. Фото збережено, внесіть вручну.';

            // У чаті кухні нічого не пишемо — питання йдуть власнику.
            $this->notifyChatId
                ? $telegram->sendMessage($this->notifyChatId, $miss)
                : $telegram->sendToOwner($miss.' (з чату кухні)');

            return;
        }

        $telegram->reactToMessage($this->chatId, $this->messageId, '✅');

        $items = $document->items()->count();
        $sum   = number_format((float) $document->total_sum, 0, ',', ' ');
        $url   = \App\Filament\Resources\StockDocumentResource::getUrl('edit', ['record' => $document]);

        $text = "🧾 <b>Чернетка накладної</b>\n"
            .($document->supplier?->name ? $document->supplier->name."\n" : '')
            ."Позицій: {$items} · сума рядків: {$sum} ₴\n"
            .($document->ai_comment ? e(mb_substr($document->ai_comment, 0, 600))."\n" : '')
            ."Перевірити й провести: {$url}";

        // Постачальника в бланку часто немає — питаємо кнопками, а не текстом.
        $keyboard = null;

        if (! $document->supplier_id) {
            $text    .= "\n\n<b>Чий це прихід?</b>";
            $keyboard = $suppliers->keyboard($document);
        }

        $chatId = $this->notifyChatId ?: $telegram->ownerChatId();

        if ($chatId) {
            $telegram->sendMessage($chatId, $text, $keyboard);
        }
    }
}
