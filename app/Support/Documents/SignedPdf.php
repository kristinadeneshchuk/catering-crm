<?php

namespace App\Support\Documents;

/**
 * Банківські квитанції (monobank та інші з КЕП) приходять не чистим PDF, а
 * PDF, загорнутим у підписаний контейнер PKCS#7/CMS. OpenSSL його не
 * розгорне — підпис за українським ДСТУ йому невідомий, — але сам PDF лежить
 * усередині цілим шматком (OCTET STRING), тож його можна просто вирізати.
 * Підпис нам не потрібен: оригінал файлу зберігаємо поруч.
 */
class SignedPdf
{
    /** Чистий PDF з підписаного контейнера або null, якщо це не він. */
    public static function unwrap(string $bytes): ?string
    {
        // Звичайний PDF — нічого робити не треба.
        if (str_starts_with($bytes, '%PDF-')) {
            return null;
        }

        // DER-контейнер починається з SEQUENCE (0x30).
        if ($bytes === '' || $bytes[0] !== "\x30") {
            return null;
        }

        $start = strpos($bytes, '%PDF-');

        if ($start === false) {
            return null;
        }

        // Перед PDF — заголовок OCTET STRING: 0x04, далі довжина
        // (коротка, або 0x81..0x84 + 1..4 байти довжини).
        for ($n = 0; $n <= 4; $n++) {
            $headerAt = $start - 2 - $n;

            if ($headerAt < 0 || $bytes[$headerAt] !== "\x04") {
                continue;
            }

            $lenByte = ord($bytes[$headerAt + 1]);

            if ($n === 0 && $lenByte < 0x80) {
                $length = $lenByte;
            } elseif ($n > 0 && $lenByte === 0x80 + $n) {
                $length = 0;
                for ($i = 0; $i < $n; $i++) {
                    $length = ($length << 8) | ord($bytes[$headerAt + 2 + $i]);
                }
            } else {
                continue;
            }

            if ($length > 0 && $start + $length <= strlen($bytes)) {
                return substr($bytes, $start, $length);
            }
        }

        // Запасний шлях: від %PDF- до останнього %%EOF.
        $end = strrpos($bytes, '%%EOF');

        return $end !== false && $end > $start ? substr($bytes, $start, $end + 5 - $start)."\n" : null;
    }
}
