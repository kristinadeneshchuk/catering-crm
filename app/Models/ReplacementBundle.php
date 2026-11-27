<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReplacementBundle extends Model
{
    protected $fillable = ['name', 'description', 'show_on_menu', 'menu_sort'];

    protected $casts = ['show_on_menu' => 'boolean', 'menu_sort' => 'integer'];

    protected static function booted(): void
    {
        // Шаблон видаляють — його заміни страв зникають із живих замовлень.
        static::deleting(function (self $bundle) {
            $bundle->clientIdsToResync = $bundle->clients()->pluck('clients.id')
                ->merge(Order::whereIn('tariff_id', Tariff::where('replacement_bundle_id', $bundle->id)->pluck('id'))->pluck('client_id'))
                ->filter()->unique()->values()->all();
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

    /** Тарифи, що продають цю лінійку: замовлення з таким тарифом отримує заміни страв. */
    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class, 'replacement_bundle_id');
    }

    /** Усі клієнти, чиї замовлення цей шаблон зачіпає: прив'язані вручну й через тариф. */
    public function affectedClients()
    {
        $ids = $this->clients()->pluck('clients.id')
            ->merge(Order::whereIn('tariff_id', $this->tariffs()->pluck('id'))->pluck('client_id'))
            ->filter()->unique();

        return Client::whereIn('id', $ids)->get();
    }

    /** [dish_id => Dish]: яку страву дати замість страви меню. */
    public function dishSwapMap(): array
    {
        return $this->dishSwaps()->with('replacementDish.dishIngredients.ingredient', 'replacementDish.dishIngredients.childDish.dishIngredients.ingredient')->get()
            ->filter(fn ($s) => $s->replacementDish)
            ->mapWithKeys(fn ($s) => [(int) $s->original_dish_id => $s->replacementDish])
            ->all();
    }
}
