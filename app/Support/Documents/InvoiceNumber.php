<?php

namespace App\Support\Documents;

/**
 * Номер накладної для порівняння: «№ ХВ00128602» (кирилицею) і «XB00128602»
 * (латиницею) — той самий номер. Кирилиця, схожа на латиницю, → латиниця,
 * без пробілів, «№» і крапок, великими літерами.
 */
class InvoiceNumber
{
    private const LOOKALIKE = [
        'А' => 'A', 'В' => 'B', 'Е' => 'E', 'І' => 'I', 'К' => 'K', 'М' => 'M', 'Н' => 'H',
        'О' => 'O', 'Р' => 'P', 'С' => 'C', 'Т' => 'T', 'Х' => 'X', 'У' => 'Y',
    ];

    public static function normalize(?string $number): ?string
    {
        $n = mb_strtoupper(trim((string) $number));
        $n = strtr($n, self::LOOKALIKE);
        $n = preg_replace('/[\s№#.\-\/]+/u', '', $n);

        return $n === '' ? null : mb_substr($n, 0, 64);
    }

    /** Номер накладної з призначення платежу: «Оплата товару №ХВ00128602 від 07.10.2026». */
    public static function fromPurpose(?string $purpose): ?string
    {
        if (preg_match('/№\s*([\p{L}\p{N}\-\/]{3,})/u', (string) $purpose, $m)) {
            return self::normalize($m[1]);
        }

        return null;
    }
}
