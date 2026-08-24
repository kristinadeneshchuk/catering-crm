<?php

namespace App\Support;

/**
 * Телефон — це ідентифікатор клієнта, тому він мусить бути одним рядком
 * незалежно від того, як його набрали.
 *
 * «+380 67 245 80 80», «0672458080» і «38 (067) 245-80-80» — той самий номер
 * і той самий кабінет. Без зведення до канонічного вигляду клієнт побачив би
 * порожню історію замовлень і вирішив, що ми їх загубили.
 */
class Phone
{
    /** Тільки цифри, у вигляді 380XXXXXXXXX. Порожньо, якщо це не номер. */
    public static function normalize(?string $phone): ?string
    {
        $digits = preg_replace('~\D+~', '', (string) $phone);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '380') => $digits,
            strlen($digits) === 11 && str_starts_with($digits, '80') => '3'.$digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '38'.$digits,
            strlen($digits) === 9 => '380'.$digits,
            default => null,
        };
    }

    /** Той самий номер у вигляді, у якому його показують людині. */
    public static function format(?string $phone): ?string
    {
        $digits = self::normalize($phone);

        if (! $digits) {
            return $phone;
        }

        return sprintf(
            '+380 %s %s %s %s',
            substr($digits, 3, 2),
            substr($digits, 5, 3),
            substr($digits, 8, 2),
            substr($digits, 10, 2),
        );
    }
}
