<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplacementBundle extends Model
{
    protected $fillable = ['name', 'description'];

    protected static function booted(): void
    {
        // Шаблон видаляють — його заміни страв зникають із живих замовлень.
        static::deleting(function (self $bundle) {
            $bundle->clientIdsToResync = $bundle->clients()->pluck('clients.id')->all();
        });
        static::deleted(function (self $bundle) {
            $swaps = app(\App\Services\Orders\BundleDishSwaps::class);
            foreach (Client::whereIn('id', $bundle->clientIdsToResync)->get() as $client) {
                $swaps->syncClient($client);
            }
        });
    }

    /** @var int[] */
    public array $clientIdsToResync = [];

    /** Правила «продукт → продукт». Заміни страв — окремо, у dishSwaps(). */
    public function items(): HasMany
    {
        return $this->hasMany(ReplacementBundleItem::class, 'bundle_id')->whereNotNull('original_ingredient_id');
    }

    /** Правила «страва → страва»: у замовленні стають записом заміни страви цілком. */
    public function dishSwaps(): HasMany
    {
        return $this->hasMany(ReplacementBundleItem::class, 'bundle_id')->whereNotNull('original_dish_id');
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
