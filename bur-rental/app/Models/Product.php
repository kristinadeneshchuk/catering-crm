<?php

namespace App\Models;

use App\Services\Availability;
use App\Services\Search\ProductSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Product extends Model
{
    protected $guarded = [];

    /**
     * Вітрина бачить лише опубліковане. Імпортовані чернетки живуть у базі,
     * але на сайт не потрапляють, доки менеджер їх не перевірить; адмінка
     * знімає цей scope явно.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('published', fn (Builder $query) => $query->where('published', true));

        // Пошуковий рядок збирається при кожному збереженні — інакше товар,
        // якому в адмінці поміняли бренд, шукався б за старим.
        static::saving(fn (Product $product) => $product->search_text = app(ProductSearch::class)->haystack($product));
    }

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'key_specs' => 'array',
            'kit' => 'array',
            'not_included' => 'array',
            'weight_kg' => 'float',
            'rating' => 'float',
            'published' => 'bool',
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

    /**
     * Дати, у які модель уже не взяти, згруповані по філії.
     * День потрапляє сюди, лише коли зайняті всі екземпляри філії.
     *
     * @return Collection<int, list<string>>
     */
    public function busyByBranch(?string $from = null, ?string $to = null): Collection
    {
        $availability = app(Availability::class);
        $from ??= now()->toDateString();
        $to ??= now()->addMonths(3)->toDateString();

        return $this->branches->mapWithKeys(fn (Branch $branch) => [
            $branch->id => $availability->fullDates($this, $branch, $from, $to),
        ]);
    }

    public function isFreeAt(Branch $branch, string $from, string $to, int $qty = 1): bool
    {
        return app(Availability::class)->isFree($this, $branch, $from, $to, $qty);
    }

    public function freeUnitsAt(Branch $branch, string $from, string $to): int
    {
        return app(Availability::class)->freeUnits($this, $branch, $from, $to);
    }

    /* ——— пошук і фільтри ——— */

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        return app(ProductSearch::class)->apply($query, $term);
    }

    public function scopeInBranch(Builder $query, ?Branch $branch): Builder
    {
        return $branch
            ? $query->whereHas('branches', fn (Builder $b) => $b->whereKey($branch->id))
            : $query;
    }

    /**
     * Тільки вільні на діапазон — тумблер, заради якого сюди й приходять.
     *
     * Позиція випадає з видачі лише тоді, коли в якийсь день діапазону зайнято
     * не менше екземплярів, ніж є на складі: два з трьох перфораторів в оренді —
     * це ще «вільно».
     */
    public function scopeFreeBetween(Builder $query, ?string $from, ?string $to, ?Branch $branch = null): Builder
    {
        if (! $from || ! $to) {
            return $query;
        }

        return $query->whereNotExists(function ($sub) use ($from, $to, $branch) {
            $sub->from('unavailable_dates as ud')
                ->join('inventory as inv', function ($join) {
                    $join->on('inv.product_id', '=', 'ud.product_id')
                        ->on('inv.branch_id', '=', 'ud.branch_id');
                })
                ->whereColumn('ud.product_id', 'products.id')
                ->whereBetween('ud.date', [$from, $to])
                ->when($branch, fn ($q) => $q->where('ud.branch_id', $branch->id))
                ->groupBy('ud.product_id', 'ud.branch_id', 'ud.date')
                ->havingRaw('SUM(ud.qty) >= MIN(inv.qty)')
                ->selectRaw('1');
        });
    }
}
