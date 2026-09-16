<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Голос у текст. Claude аудіо не приймає, тому розпізнавання — окремим
 * сервісом. Зараз це Whisper через API (ключ уже є на сервері); за потреби
 * можна підмінити локальним, не чіпаючи решту коду.
 */
class SpeechToText
{
    public function enabled(): bool
    {
        return filled(config('services.openai.key'));
    }

    /** @return string|null розпізнаний текст або null, якщо не вийшло */
    public function transcribe(string $path, string $language = 'uk'): ?string
    {
        if (! $this->enabled() || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        try {
            $response = Http::withToken((string) config('services.openai.key'))
                ->timeout((int) config('services.openai.transcribe_timeout', 120))
                ->attach('file', Storage::disk('local')->get($path), basename($path))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model'    => (string) config('services.openai.transcribe_model', 'whisper-1'),
                    'language' => $language,
                    // Підказка моделі про контекст: так менше помилок у назвах продуктів.
                    'prompt'   => 'Кухня служби доставки їжі. Продукти, кілограми, грами, штуки, причини списання.',
                ]);

            if (! $response->successful()) {
                Log::warning('[SpeechToText] не вдалося', ['status' => $response->status()]);

                return null;
            }

            $text = trim((string) ($response->json('text') ?? ''));

            return $text === '' ? null : $text;
        } catch (\Throwable $e) {
            Log::warning('[SpeechToText] помилка', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
