<?php

namespace App\Models;

use App\Services\ProductImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Фото товару. `path` — оригінал на диску public; для показу беруться
 * WebP-варіанти з тим самим іменем і суфіксом розміру: abc-md.webp.
 */
class ProductImage extends Model
{
    /** Ширина варіантів у пікселях. */
    public const SIZES = ['sm' => 400, 'md' => 800, 'lg' => 1600];

    protected $guarded = [];

    protected static function booted(): void
    {
        // Завантажили в адмінці чи командою — варіанти робляться тут, в одному місці.
        static::saved(function (ProductImage $image) {
            if ($image->wasChanged('path') || $image->wasRecentlyCreated) {
                app(ProductImages::class)->makeVariants($image);
            }
        });

        static::deleted(fn (ProductImage $image) => app(ProductImages::class)->deleteFiles($image));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variantPath(string $size): string
    {
        $dir = pathinfo($this->path, PATHINFO_DIRNAME);

        return ($dir === '.' ? '' : $dir.'/').pathinfo($this->path, PATHINFO_FILENAME).'-'.$size.'.webp';
    }

    public function url(string $size = 'md'): string
    {
        return Storage::disk('public')->url($this->variantPath($size));
    }

    public function srcset(): string
    {
        return collect(self::SIZES)
            ->map(fn (int $width, string $size) => $this->url($size).' '.$width.'w')
            ->implode(', ');
    }

    public function altFor(Product $product): string
    {
        return $this->alt ?: $product->full_name;
    }
}
