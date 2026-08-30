<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\City;
use App\Models\District;
use App\Models\Kit;
use App\Models\Product;
use Illuminate\Http\Response;

/**
 * Мапа сайту.
 *
 * Генерується запитом, а не файлом: каталог живий — товар опублікували в
 * адмінці, і він має з'явитися в мапі того ж дня, без деплою і без крону.
 * Позиції-чернетки з імпорту сюди не потрапляють — за це відповідає глобальний
 * scope `published`, знімати його тут не можна.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        // На тестовому майданчику мапи немає взагалі: robots.txt закриває сайт
        // повністю, і віддавати роботам список адрес було б суперечністю.
        abort_if(config('app.noindex'), 404);

        $urls = [
            ...$this->statics(),
            ...$this->catalog(),
            ...$this->geo(),
        ];

        $xml = view('sitemap', ['urls' => $urls])->render();

        return response($xml)->header('Content-Type', 'application/xml');
    }

    /** @return list<array<string, ?string>> */
    private function statics(): array
    {
        $urls = [$this->url(route('home'), '1.0', 'daily')];

        foreach (['terms', 'delivery', 'returns', 'contacts', 'b2b'] as $page) {
            $urls[] = $this->url(route($page), '0.4', 'monthly');
        }

        return $urls;
    }

    /** @return list<array<string, ?string>> */
    private function catalog(): array
    {
        $urls = [
            $this->url(route('catalog.index'), '0.9', 'daily'),
            $this->url(route('kits.index'), '0.7', 'weekly'),
        ];

        foreach (Category::orderBy('id')->get() as $category) {
            $urls[] = $this->url(route('category', $category), '0.8', 'daily', $category->updated_at);
        }

        foreach (Product::orderBy('id')->get() as $product) {
            $urls[] = $this->url(route('product', $product), '0.7', 'daily', $product->updated_at);
        }

        foreach (Kit::orderBy('id')->get() as $kit) {
            $urls[] = $this->url(route('kit', $kit), '0.6', 'weekly', $kit->updated_at);
        }

        // Бренд без жодного товару в мапі не потрібен: сторінка буде порожня.
        foreach (Brand::has('products')->orderBy('id')->get() as $brand) {
            $urls[] = $this->url(route('brand', $brand), '0.5', 'weekly', $brand->updated_at);
        }

        return $urls;
    }

    /** @return list<array<string, ?string>> */
    private function geo(): array
    {
        $urls = [];

        foreach (City::orderBy('id')->get() as $city) {
            $urls[] = $this->url(route('city', $city), '0.8', 'weekly');
        }

        foreach (Branch::with('city')->orderBy('id')->get() as $branch) {
            $urls[] = $this->url(route('branch', [$branch->city, $branch]), '0.6', 'monthly');
        }

        foreach (District::with('city')->orderBy('id')->get() as $district) {
            $urls[] = $this->url(route('district', [$district->city, $district]), '0.5', 'monthly');
        }

        return $urls;
    }

    /**
     * `lastmod` не обов'язковий, але з ним робот перевідвідує змінені сторінки
     * швидше, а незмінені не чіпає — на каталозі в кілька тисяч позицій це
     * різниця між «переобхід за тиждень» і «за місяць».
     *
     * @return array{loc: string, priority: string, changefreq: string, lastmod: ?string}
     */
    private function url(string $loc, string $priority, string $changefreq, $lastmod = null): array
    {
        return [
            'loc' => $loc,
            'priority' => $priority,
            'changefreq' => $changefreq,
            'lastmod' => $lastmod?->toDateString(),
        ];
    }
}
