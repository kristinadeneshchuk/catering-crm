<?php

namespace App\Services\Ai;

use App\Models\Account;
use App\Models\Supplier;

/**
 * Впізнати постачальника (з накладної чи квитанції) і рахунок-платника.
 *
 * Спершу ЄДРПОУ/ІПН, потім назва: повний збіг без пробілів і знаків, а далі
 * прізвище — «ФОП Іванков Ігор Андрійович» = «ФОП Іванков І.А. (риба)».
 */
class SupplierMatcher
{
    /** Слова, які є в кожній назві й нічого не розрізняють. */
    private const NOISE = ['фоп', 'тов', 'пп', 'фізична', 'особа', 'підприємець', 'фізичнаособапідприємець',
        'товариство', 'обмеженою', 'відповідальністю', 'україна', 'продукти', 'упаковка', 'риба'];

    public function supplierId(?string $name, ?string $code = null): ?int
    {
        $code = preg_replace('/\D+/', '', (string) $code);

        if ($code !== '' && ($byCode = Supplier::where('inn', $code)->value('id'))) {
            return (int) $byCode;
        }

        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        $needle = $this->flat($name);
        $suppliers = Supplier::get(['id', 'name']);

        foreach ($suppliers as $supplier) {
            $hay = $this->flat((string) $supplier->name);

            if ($hay !== '' && $needle !== '' && (str_contains($hay, $needle) || str_contains($needle, $hay))) {
                return (int) $supplier->id;
            }
        }

        return $this->byWords($name, $suppliers->map(fn ($s) => [$s->id, $s->name])->all());
    }

    /** Рахунок CRM, з якого платили: назви рахунків — «ФОП Настечина», «Готівка»… */
    public function accountId(?string $payer): ?int
    {
        if (trim((string) $payer) === '') {
            return null;
        }

        return $this->byWords((string) $payer, Account::get(['id', 'name'])->map(fn ($a) => [$a->id, $a->name])->all());
    }

    /**
     * Збіг за значущим словом (прізвищем) — лише якщо кандидат один.
     *
     * @param  array<int, array{0: int, 1: string}>  $candidates
     */
    private function byWords(string $name, array $candidates): ?int
    {
        $words = $this->words($name);
        $hits = [];

        foreach ($candidates as [$id, $candidateName]) {
            if (array_intersect($words, $this->words((string) $candidateName)) !== []) {
                $hits[] = (int) $id;
            }
        }

        return count(array_unique($hits)) === 1 ? $hits[0] : null;
    }

    /** @return array<int, string> */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\']{4,}/u', mb_strtolower(str_replace('ʼ', "'", $text)), $m);

        return array_values(array_diff(array_unique($m[0]), self::NOISE));
    }

    private function flat(string $text): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $text));
    }
}
