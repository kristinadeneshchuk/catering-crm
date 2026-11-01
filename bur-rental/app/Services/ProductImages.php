<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Фото товарів: збереження оригіналу і WebP-варіанти під картку, сторінку
 * товару й збільшений перегляд. Тільки GD — Imagick на shared-хостингах
 * буває не завжди, а GD є скрізь.
 */
class ProductImages
{
    /** Більше — не фото товару, а щось не те. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * Зберегти фото з байтів (завантаження за посиланням).
     *
     * @throws InvalidArgumentException якщо це не картинка
     */
    public function storeFromBytes(Product $product, string $bytes, ?string $sourceUrl = null, ?string $alt = null): ProductImage
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Файл більший за 15 МБ.');
        }

        $info = @getimagesizefromstring($bytes);
        $ext = self::MIME_EXT[$info['mime'] ?? ''] ?? null;

        if (! $info || ! $ext) {
            throw new InvalidArgumentException('Це не JPEG, PNG, WebP чи GIF.');
        }

        $path = "products/{$product->id}/".Str::uuid().".{$ext}";
        Storage::disk('public')->put($path, $bytes);

        return $product->images()->create([
            'path' => $path,
            'alt' => $alt,
            'source_url' => $sourceUrl,
            'position' => (int) $product->images()->max('position') + 1,
        ]);
    }

    /** Нарізати WebP-варіанти з оригіналу. Викликається з моделі після збереження. */
    public function makeVariants(ProductImage $image): void
    {
        $disk = Storage::disk('public');
        $source = @imagecreatefromstring((string) $disk->get($image->path));

        if (! $source) {
            return;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        foreach (ProductImage::SIZES as $size => $target) {
            $w = min($width, $target);
            $h = (int) round($height * $w / $width);

            $canvas = imagecreatetruecolor($w, $h);
            // Прозорий PNG на білому: товари на білому тлі — норма каталогу.
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height);

            ob_start();
            imagewebp($canvas, null, 82);
            $disk->put($image->variantPath($size), (string) ob_get_clean());
            imagedestroy($canvas);
        }

        imagedestroy($source);

        $image->forceFill(['width' => $width, 'height' => $height])->saveQuietly();
    }

    public function deleteFiles(ProductImage $image): void
    {
        Storage::disk('public')->delete([
            $image->path,
            ...array_map(fn ($size) => $image->variantPath($size), array_keys(ProductImage::SIZES)),
        ]);
    }
}
