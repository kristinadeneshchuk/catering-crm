<?php

namespace App\Services\Import;

use DOMDocument;
use DOMXPath;

/**
 * Розбір HTML-сторінок budprokat.kiev.ua.
 *
 * Чисті функції без HTTP — щоб парсинг можна було ганяти тестами на фікстурах
 * і чинити селектори, не смикаючи живий сайт. Селектори написані з запасом
 * (кілька евристик на кожне поле): чужа верстка може змінитися будь-коли,
 * і перший запуск на живому HTML може вимагати одного підкручування тут.
 */
class BudprokatParser
{
    /**
     * Посилання на категорії зі сторінки (меню + плитки каталогу).
     *
     * @return list<array{name: string, url: string}>
     */
    public function categories(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $found = [];

        // Категорії на budprokat живуть під /ua/arenda-*/ і /ua/catalog/*.
        foreach ($xpath->query('//a[@href]') as $a) {
            $href = $this->absolute(trim($a->getAttribute('href')), $baseUrl);
            $name = $this->clean($a->textContent);

            if (! $href || mb_strlen($name) < 3) {
                continue;
            }

            $path = parse_url($href, PHP_URL_PATH) ?? '';

            $isCategory = preg_match('~^/ua/(arenda-[^/]+|catalog/[^/]+)/?$~u', $path)
                && ! preg_match('~ysloviya|dostavka|kontakt|о-nas|blog~ui', $path);

            if ($isCategory) {
                $found[$href] = ['name' => $name, 'url' => $href];
            }
        }

        return array_values($found);
    }

    /**
     * Посилання на товари зі сторінки категорії.
     *
     * @return list<string>
     */
    public function productLinks(string $html, string $baseUrl): array
    {
        $xpath = $this->xpath($html);
        $links = [];

        // Спершу пробуємо явні картки товарів; якщо верстка інша —
        // беремо всі посилання глибші за категорію.
        $queries = [
            "//*[contains(@class,'product')]//a[@href]",
            "//*[contains(@class,'item')]//a[@href]",
            '//a[@href]',
        ];

        foreach ($queries as $query) {
            foreach ($xpath->query($query) as $a) {
                $href = $this->absolute(trim($a->getAttribute('href')), $baseUrl);
                $path = $href ? (parse_url($href, PHP_URL_PATH) ?? '') : '';

                // Товар — глибше одного сегмента після /ua/, без службових сторінок.
                if (preg_match('~^/ua/[^/]+/[^/]+/?$~u', $path)
                    && ! preg_match('~catalog|page|sort|filter~ui', $path)) {
                    $links[$href] = $href;
                }
            }

            if (count($links) >= 3) {
                break;
            }
        }

        return array_values($links);
    }

    /**
     * Дані товару зі сторінки.
     *
     * @return array{name: ?string, price: ?int, deposit: ?int, description: ?string,
     *               specs: array<string, string>, breadcrumbs: list<string>, url: string}
     */
    public function product(string $html, string $url): array
    {
        $xpath = $this->xpath($html);
        $text = $this->clean($xpath->document->textContent);

        return [
            'url' => $url,
            'name' => $this->firstText($xpath, ['//h1']),
            'price' => $this->priceNear($text, ['добу', 'доба', 'день', 'сутки']),
            'deposit' => $this->priceNear($text, ['застав', 'залог']),
            'description' => $this->firstText($xpath, [
                "//*[contains(@class,'description')]//p",
                "//*[contains(@class,'product')]//p",
                '//h1/following::p[1]',
            ]),
            'specs' => $this->specs($xpath),
            'breadcrumbs' => $this->breadcrumbs($xpath),
        ];
    }

    /* ——— внутрішнє ——— */

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        // Чужий HTML ніколи не валідний — глушимо попередження libxml.
        @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($doc);
    }

    /** Ціла ціна в грн, що стоїть у тексті поруч із одним із маркерів. */
    private function priceNear(string $text, array $markers): ?int
    {
        foreach ($markers as $marker) {
            // «250 грн/добу», «добу — 250 грн», «застава 4000 грн» тощо.
            $patterns = [
                '~(\d[\d\s]{0,8})\s*(?:грн|₴)[^.\n]{0,20}'.$marker.'~ui',
                '~'.$marker.'[^.\n]{0,30}?(\d[\d\s]{0,8})\s*(?:грн|₴)~ui',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text, $m)) {
                    $value = (int) preg_replace('~\D~', '', $m[1]);

                    if ($value >= 20 && $value <= 100000) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function specs(DOMXPath $xpath): array
    {
        $specs = [];

        foreach ($xpath->query('//table//tr') as $row) {
            $cells = iterator_to_array($row->getElementsByTagName('td'));

            if (count($cells) >= 2) {
                $key = $this->clean($cells[0]->textContent);
                $value = $this->clean($cells[1]->textContent);

                if ($key !== '' && $value !== '' && mb_strlen($key) < 60) {
                    $specs[$key] = $value;
                }
            }
        }

        return array_slice($specs, 0, 20, true);
    }

    /** @return list<string> */
    private function breadcrumbs(DOMXPath $xpath): array
    {
        foreach ([
            "//*[contains(@class,'breadcrumb')]//a",
            "//*[contains(@class,'crumb')]//a",
            "//nav//a[contains(@href,'/ua/')]",
        ] as $query) {
            $items = [];

            foreach ($xpath->query($query) as $a) {
                $name = $this->clean($a->textContent);

                if ($name !== '' && mb_strlen($name) > 2) {
                    $items[] = $name;
                }
            }

            if (count($items) >= 2) {
                return $items;
            }
        }

        return [];
    }

    private function firstText(DOMXPath $xpath, array $queries): ?string
    {
        foreach ($queries as $query) {
            $node = $xpath->query($query)->item(0);

            if ($node && ($text = $this->clean($node->textContent)) !== '') {
                return $text;
            }
        }

        return null;
    }

    private function absolute(string $href, string $baseUrl): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
            return null;
        }

        if (str_starts_with($href, 'http')) {
            // Чужі домени не наша справа: збираємо тільки той сайт, який обходимо.
            return parse_url($href, PHP_URL_HOST) === parse_url($baseUrl, PHP_URL_HOST) ? $href : null;
        }

        /*
        | Схему й порт беремо з бази, а не приліплюємо «https://» константою:
        | інакше обхід тестового дзеркала на http або на нестандартному порту
        | мовчки перетворюється на звернення до чужої адреси.
        */
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);

        return $scheme.'://'.$host.($port ? ':'.$port : '').'/'.ltrim($href, '/');
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $text));
    }
}
