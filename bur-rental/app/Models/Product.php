<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'key_specs' => 'array',
            'kit' => 'array',
            'not_included' => 'array',
            'weight_kg' => 'float',
            'rating' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /* ——— зв'язки ——— */

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(TariffTier::class)->orderBy('min_days');
    }

    public function extras(): BelongsToMany
    {
        return $this->belongsToMany(Extra::class)->withPivot('position')->orderBy('position');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'inventory')->withPivot('qty');
    }

    public function unavailableDates(): HasMany
    {
        return $this->hasMany(UnavailableDate::class);
    }

    public function reviews()
    {
        return $this->morphMany(Review::class, 'reviewable')->latest('published_at');
    }

    public function faqs()
    {
        return $this->morphMany(Faq::class, 'faqable')->orderBy('position');
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_id')
            ->wherePivot('kind', 'with')
            ->withPivot('position')
            ->orderBy('position');
    }

    public function similar(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_related', 'product_id', 'related_id')
            ->wherePivot('kind', 'similar')
            ->withPivot('position')
            ->orderBy('position');
    }

    /* ——— тариф ——— */

    public function tierFor(int $days): ?TariffTier
    {
        return $this->tiers->first(fn (TariffTier $t) => $t->covers($days));
    }

    public function priceFor(int $days): int
    {
        return $this->tierFor($days)?->price ?? $this->base_price;
    }

    /** Найнижча ціна за день — те саме «від 240 ₴/день» у картці. */
    public function getMinPriceAttribute(): int
    {
        return (int) ($this->tiers->min('price') ?? $this->base_price);
    }

    public function getMinPriceTierAttribute(): ?TariffTier
    {
        return $this->tiers->sortBy('price')->first();
    }

    /* ——— наявність ——— */

    /** Зайняті дати, згруповані по філії: [branch_id => ['2026-08-13', …]]. */
    public function busyByBranch(): Collection
    {
        return $this->unavailableDates
            ->groupBy('branch_id')
            ->map(fn ($rows) => $rows->pluck('date')->map(fn ($d) => $d->toDateString())->values());
    }

    public function isFreeAt(Branch $branch, string $from, string $to): bool
    {
        return $this->unavailableDates
            ->where('branch_id', $branch->id)
            ->every(fn (UnavailableDate $d) => $d->date->toDateString() < $from || $d->date->toDateString() > $to);
    }

    /* ——— пошук і фільтри ——— */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('sku', 'like', "%{$term}%")
                ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$term}%"));
        });
    }

    public function scopeInBranch(Builder $query, ?Branch $branch): Builder
    {
        return $branch
            ? $query->whereHas('branches', fn (Builder $b) => $b->whereKey($branch->id))
            : $query;
    }

    /** Тільки вільні на діапазон — тумблер, заради якого сюди й приходять. */
    public function scopeFreeBetween(Builder $query, ?string $from, ?string $to, ?Branch $branch = null): Builder
    {
        if (! $from || ! $to) {
            return $query;
        }

        return $query->whereDoesntHave('unavailableDates', function (Builder $q) use ($from, $to, $branch) {
            $q->whereBetween('date', [$from, $to]);
            if ($branch) {
                $q->where('branch_id', $branch->id);
            }
        });
    }
}
