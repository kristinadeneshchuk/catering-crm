<?php

namespace App\Services\Bank;

use App\Models\Account;
use App\Models\BankOperation;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Closure;
use RuntimeException;

/**
 * Підтягує виписку monobank у bank_operations. Повторний запуск безпечний:
 * унікальний ключ (рахунок, id операції) відкидає дублі.
 */
class MonobankSync
{
    public const MAX_DAYS = 31;
    private const PAGE = 500;

    /** Пауза між запитами до банку (ліміт 1 на 60 с). У тестах підміняємо. */
    private Closure $pause;

    public function __construct(?Closure $pause = null)
    {
        $this->pause = $pause ?? fn () => sleep(61);
    }

    public function client(Account $account): MonobankClient
    {
        if (!$account->hasMonoToken()) {
            throw new RuntimeException("Рахунок «{$account->name}»: токен monobank не задано");
        }

        return new MonobankClient((string) $account->mono_token);
    }

    /**
     * Додані нові операції за останні $days діб.
     *
     * @return array{inserted: int, fetched: int}
     */
    public function sync(Account $account, int $days = self::MAX_DAYS): array
    {
        return $this->syncRange($account, now()->subDays(max($days, 1)), now());
    }

    /**
     * Довільний період. Банк віддає не більше 31 доби за запит —
     * довший період ріжемо на вікна, кожне ще й сторінками по 500.
     *
     * @return array{inserted: int, fetched: int}
     */
    public function syncRange(Account $account, CarbonInterface $from, CarbonInterface $to): array
    {
        $client = $this->client($account);
        $requested = false;

        if (!$account->mono_account_id) {
            $this->resolveAccount($account, $client);
            $requested = true;
        }

        $fromTs = $from->timestamp;
        $toTs = min($to->timestamp, now()->timestamp);
        if ($fromTs >= $toTs) {
            throw new RuntimeException('Дата «з» має бути раніше за «по»');
        }

        $fetched = 0;
        $inserted = 0;

        // Від нових до старих: вікно [winFrom, winTo] ≤ 31 доби.
        $winTo = $toTs;
        while ($winTo > $fromTs) {
            $winFrom = max($fromTs, $winTo - self::MAX_DAYS * 86400);
            $pageTo = $winTo;

            while (true) {
                if ($requested) ($this->pause)();
                $items = $client->statement($account->mono_account_id, $winFrom, $pageTo);
                $requested = true;

                $fetched += count($items);
                $inserted += $this->store($account, $items);

                // Повна сторінка — у вікні є ще старіші операції.
                if (count($items) < self::PAGE) break;
                $oldest = min(array_column($items, 'time'));
                if ($oldest <= $winFrom) break;
                $pageTo = $oldest - 1;
            }

            $winTo = $winFrom - 1;
        }

        $account->forceFill(['mono_synced_at' => now()])->save();

        return ['inserted' => $inserted, 'fetched' => $fetched];
    }

    /**
     * Рахунок з client-info за типом (ФОП / біла / чорна), у гривні.
     * Один токен — одна людина, тож з токена Строї видно і ФОП, і її картки.
     */
    private function resolveAccount(Account $account, MonobankClient $client): void
    {
        $type = $account->mono_account_type ?: 'fop';
        $accounts = collect($client->clientInfo()['accounts'] ?? [])
            ->filter(fn ($a) => (int) ($a['currencyCode'] ?? 0) === 980);

        $found = $accounts->first(fn ($a) => ($a['type'] ?? null) === $type)
            ?? ($type === 'fop' ? $accounts->first() : null);

        if (!$found) {
            $label = Account::MONO_TYPES[$type] ?? $type;
            throw new RuntimeException("Рахунок «{$account->name}»: у monobank немає гривневого рахунку «{$label}»");
        }

        $account->forceFill(['mono_account_id' => $found['id'], 'mono_iban' => $found['iban'] ?? null])->save();
    }

    private function store(Account $account, array $items): int
    {
        $now = now();
        $rows = array_map(fn ($i) => [
            'account_id'     => $account->id,
            'bank_id'        => (string) $i['id'],
            'operated_at'    => Carbon::createFromTimestamp((int) $i['time'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
            'amount'         => round(((int) $i['amount']) / 100, 2),
            'balance_after'  => isset($i['balance']) ? round(((int) $i['balance']) / 100, 2) : null,
            'description'    => $i['description'] ?? null,
            'comment'        => $i['comment'] ?? null,
            'counter_name'   => $i['counterName'] ?? null,
            'counter_iban'   => $i['counterIban'] ?? null,
            'counter_edrpou' => $i['counterEdrpou'] ?? null,
            'mcc'            => $i['mcc'] ?? null,
            'raw'            => json_encode($i, JSON_UNESCAPED_UNICODE),
            'created_at'     => $now,
            'updated_at'     => $now,
        ], $items);

        return $rows ? BankOperation::insertOrIgnore($rows) : 0;
    }
}
