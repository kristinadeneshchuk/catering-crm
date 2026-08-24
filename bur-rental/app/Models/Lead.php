<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    /** Той самий канонічний телефон, що й у бронях — менеджер шукає по ньому. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }
}
