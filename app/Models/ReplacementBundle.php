<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplacementBundle extends Model
{
    protected $fillable = ['name', 'description'];

    public function items(): HasMany
    {
        return $this->hasMany(ReplacementBundleItem::class, 'bundle_id');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(
            Client::class,
            'client_replacement_bundle',
            'replacement_bundle_id',
            'client_id'
        )->withTimestamps();
    }
}
