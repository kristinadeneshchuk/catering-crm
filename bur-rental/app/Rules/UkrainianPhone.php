<?php

namespace App\Rules;

use App\Support\Phone;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Український мобільний у будь-якому написанні.
 *
 * «+380 67 245 80 80», «0672458080», «38(067)245-80-80» — усе це той самий
 * номер, і форма не має права відмовляти через дужки. У базу він однаково
 * ляже канонічним: за це відповідає мутатор моделі.
 */
class UkrainianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Phone::normalize(is_string($value) ? $value : null)) {
            $fail('Введіть номер у форматі +380 XX XXX XX XX');
        }
    }
}
