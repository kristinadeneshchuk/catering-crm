<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['published_at' => 'date'];
    }

    public function reviewable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeGoogle($query)
    {
        return $query->where('source', 'google');
    }
}
