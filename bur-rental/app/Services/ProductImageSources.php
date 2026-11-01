<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Фото за посиланням: пряме посилання на картинку або сторінка товару на
 * сайті виробника. Зі сторінки береться головне фото, яке сайт сам віддає
 * для прев'ю: og:image, twitter:image або image з розмітки Product.
 */
class ProductImageSources
{
    private const AGENT = 'Mozilla/5.0 (compatible; TekhparkCatalog/1.0; +https://tekhpark.com.ua)';

    public function __construct(private readonly ProductImages $images) {}

    /** @throws InvalidArgumentException з людським поясненням, що не так */
    public function import(Product $product, string $url, ?string $alt = null): ProductImage
    {
        $response = $this->get($url);
        $type = strtolower((string) $response->header('Content-Type'));

        if (str_starts_with($type, 'image/')) {
            return $this->images->storeFromBytes($product, $response->body(), $url, $alt);
        }

        $imageUrl = $this->imageFromPage($response->body(), $url);

        if (! $imageUrl) {
            throw new InvalidArgumentException('На сторінці не знайшлося головного фото (og:image).');
        }

        $image = $this->get($imageUrl);

        return $this->images->storeFromBytes($product, $image->body(), $url, $alt);
    }

    /** Головне фото сторінки: og:image → twitter:image → JSON-LD Product.image. */
    public function imageFromPage(string $html, string $pageUrl): ?string
    {
        foreach (['og:image:secure_url', 'og:image', 'twitter:image', 'twitter:image:src'] as $name) {
            if (preg_match('~<meta[^>]+(?:property|name)=["\']'.preg_quote($name, '~').'["\'][^>]*content=["\']([^"\']+)~i', $html, $m)
                || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']'.preg_quote($name, '~').'["\']~i', $html, $m)) {
                return $this->absolute(html_entity_decode($m[1]), $pageUrl);
            }
        }

        preg_match_all('~<script[^>]+application/ld\+json[^>]*>(.*?)</script>~is', $html, $blocks);

        foreach ($blocks[1] as $json) {
            $data = json_decode(trim($json), true);

            foreach ($data['@graph'] ?? [$data] as $node) {
                $types = (array) ($node['@type'] ?? []);

                if (in_array('Product', $types, true) && ! empty($node['image'])) {
                    $image = is_array($node['image']) ? ($node['image']['url'] ?? $node['image'][0] ?? null) : $node['image'];

                    if (is_array($image)) {
                        $image = $image['url'] ?? null;
                    }

                    return $image ? $this->absolute($image, $pageUrl) : null;
                }
            }
        }

        return null;
    }

    private function get(string $url)
    {
        $response = Http::withHeaders(['User-Agent' => self::AGENT, 'Accept-Language' => 'uk,en;q=0.8'])
            ->timeout(25)
            ->retry(2, 1000, throw: false)
            ->get($url);

        if (! $response->successful()) {
            throw new InvalidArgumentException("Сайт відповів {$response->status()} на {$url}");
        }

        return $response;
    }

    private function absolute(string $url, string $base): string
    {
        if (preg_match('~^https?://~i', $url)) {
            return $url;
        }

        $parts = parse_url($base);
        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($url, '//')) {
            return $parts['scheme'].':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        // Відносно папки сторінки: /catalog/item + img/a.jpg → /catalog/img/a.jpg
        $dir = rtrim(str_contains($parts['path'] ?? '', '/') ? dirname($parts['path'].'x') : '', '/');

        return $origin.$dir.'/'.$url;
    }
}
