<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Одноразовий код входу. Сам код у базі не лежить — тільки його хеш.
 */
class ClientLoginCode extends Model
{
    protected $guarded = [];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }
}
