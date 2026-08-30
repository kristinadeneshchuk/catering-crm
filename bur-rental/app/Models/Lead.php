<?php

namespace App\Models;

use App\Support\Phone;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['campaign' => 'array'];
    }

    /** Рекламна мітка одним рядком: «google / cpc / perforator-kyiv». */
    public function getCampaignLabelAttribute(): ?string
    {
        if (! $this->campaign) {
            return null;
        }

        return collect(['utm_source', 'utm_medium', 'utm_campaign'])
            ->map(fn (string $key) => $this->campaign[$key] ?? null)
            ->filter()
            ->join(' / ') ?: null;
    }

    /** Той самий канонічний телефон, що й у бронях — менеджер шукає по ньому. */
    protected function phone(): Attribute
    {
        return Attribute::set(fn (?string $value) => Phone::format($value));
    }
}
