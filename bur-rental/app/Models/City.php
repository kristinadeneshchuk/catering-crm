<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class)->orderBy('position');
    }

    public function districts(): HasMany
    {
        return $this->hasMany(District::class);
    }

    public function deliveryZones(): HasMany
    {
        return $this->hasMany(DeliveryZone::class)->orderBy('position');
    }

    public function reviews()
    {
        return $this->morphMany(Review::class, 'reviewable')->latest('published_at');
    }
}
