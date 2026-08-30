<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Review extends Model
{
    protected $guarded = [];

    /**
     * Демонстраційні відгуки з сидів на сайт не потрапляють.
     *
     * Вигаданий відгук — це обман клієнта і порушення правил пошукових систем
     * водночас. Показати їх можна лише свідомо: `DEMO_REVIEWS=true`, і тільки
     * на майданчику, закритому від індексації.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('real', function (Builder $query) {
            if (! config('content.demo_reviews')) {
                $query->where('demo', false);
            }
        });
    }

    protected function casts(): array
    {
        return ['published_at' => 'date', 'demo' => 'bool'];
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
