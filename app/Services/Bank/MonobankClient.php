<?php

namespace App\Services\Bank;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Personal API monobank (https://api.monobank.ua/docs/).
 * Ліміт банку — 1 запит на 60 с на токен, тому паузи робить MonobankSync.
 * Токен іде лише в заголовок X-Token і ніде не логуємо.
 */
class MonobankClient
{
    public function __construct(private string $token) {}

    /** Рахунки клієнта: id, type (fop/black/white…), currencyCode, iban, balance (копійки). */
    public function clientInfo(): array
    {
        return $this->get('/personal/client-info');
    }

    /** Виписка за [from, to] (unix), не більше 31 доби + 1 год; до 500 операцій, нові першими. */
    public function statement(string $accountId, int $from, int $to): array
    {
        return $this->get("/personal/statement/{$accountId}/{$from}/{$to}");
    }

    private function get(string $path): array
    {
        $response = Http::baseUrl(rtrim((string) config('services.monobank.base_url'), '/'))
            ->withHeaders(['X-Token' => $this->token])
            ->timeout(30)
            ->get($path);

        if ($response->status() === 429) {
            throw new RuntimeException('monobank: забагато запитів (ліміт 1 на 60 с), спробуйте за хвилину');
        }
        if ($response->failed()) {
            $msg = $response->json('errorDescription') ?? mb_substr($response->body(), 0, 200);
            throw new RuntimeException("monobank {$response->status()}: {$msg}");
        }

        return (array) $response->json();
    }
}
