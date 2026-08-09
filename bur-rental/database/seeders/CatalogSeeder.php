<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Extra;
use App\Models\Kit;
use App\Models\KitItem;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $brands = $this->brands();
        $cats = $this->categories();
        $subs = $this->subcategories($cats);
        $made = $this->products($brands, $cats, $subs);
        $extras = $this->extras($cats);

        $this->attachExtras($made, $extras);
        $this->relate($made);
        $this->kits($made);
        $this->recountCategories();
    }

    /** @return Collection<string, Brand> */
    private function brands()
    {
        return collect([
            ['bosch', 'Bosch', 'Німеччина', '#0a5ca8', 'Bosch Professional — синя лінійка, зроблена під щоденне навантаження на об\'єкті, а не під полицю в гаражі. Ресурс двигуна і редуктора розрахований на зміну, а не на годину.', 'Тримаємо Bosch у парку, бо він рівно переносить прокат: витримує 300+ видач без капремонту, а сервіс і запчастини є в Києві — заміну привозимо за години, а не за тижні.'],
            ['makita', 'Makita', 'Японія', '#00a0a0', 'Makita — бірюзовий колір на будь-якому об\'єкті. Легші корпуси при тій самій потужності, що цінують ті, хто працює з піднятими руками цілий день.', 'Беремо там, де важлива вага: стеля, штроби на висоті, довга зміна.'],
            ['dewalt', 'DeWalt', 'США', '#f6b60d', 'DeWalt — жовтий інструмент для важких умов. Міцний корпус, який пробачає падіння з риштування.', 'Ставимо на позиції, де інструмент возять по об\'єктах щодня.'],
            ['husqvarna', 'Husqvarna', 'Швеція', '#f37021', 'Husqvarna — садова й різальна техніка, розрахована на професійне навантаження, а не на два вихідні на рік.', 'Основа садового парку і всього, що ріже бетон.'],
            ['wacker', 'Wacker Neuson', 'Німеччина', '#f5c400', 'Wacker Neuson — ущільнювальна техніка: віброплити, трамбовки, глибинні вібратори. Галузевий стандарт.', 'Стандарт для ґрунту і бруківки: ресурс і ремонтопридатність у прокаті важливіші за ціну.'],
            ['karcher', 'Karcher', 'Німеччина', '#ffed00', 'Karcher — будівельні пилососи й мийки.', 'Пилосос під штроборіз обов\'язковий, інакше пил осідає в усій квартирі.'],
            ['starmix', 'Starmix', 'Німеччина', '#0067a5', 'Starmix — промислові пилососи класів M і H для бетонного й кварцового пилу.', 'Клас M — єдиний правильний вибір під штроблення і шліфування стель.'],
            ['collomix', 'Collomix', 'Німеччина', '#c8102e', 'Collomix — міксери для будівельних сумішей.', 'Клей і стяжку вручну однорідно не замісити.'],
            ['flex', 'Flex', 'Німеччина', '#e2001a', 'Flex — той самий виробник, що придумав «болгарку». Спеціалізується на шліфуванні.', '«Жираф» Flex — робоча конячка оздоблювальників, у прокаті ходить безвідмовно.'],
            ['krause', 'Krause', 'Німеччина', '#0067b1', 'Krause — драбини й риштування з сертифікацією EN 131.', 'Висота — питання безпеки, тут не економимо.'],
            ['honda', 'Honda', 'Японія', '#cc0000', 'Honda — генератори й двигуни, які заводяться після місяця простою.', 'Інверторні генератори під відключення світла: чиста синусоїда й 48 дБ.'],
            ['hyundai', 'Hyundai', 'Південна Корея', '#002c5f', 'Hyundai — бензинова техніка робочого класу: генератори, культиватори, мотопомпи.', 'Закриває нішу «потужно й недорого» там, де інверторність не потрібна.'],
            ['fubag', 'Fubag', 'Німеччина', '#e30613', 'Fubag — компресори й зварювальне обладнання.', 'Компресор під фарбування і пневмоінструмент.'],
            ['paton', 'Патон', 'Україна', '#005bab', 'Патон — київське зварювальне обладнання, яке тримає дугу при просадці напруги.', 'На об\'єктах зі старою проводкою це вирішальна перевага.'],
            ['vitals', 'Vitals', 'Латвія', '#f57c00', 'Vitals — доступне оснащення й засоби захисту.', 'Маски, окуляри, дрібне оснащення.'],
            ['limex', 'Limex', 'Україна', '#005baa', 'Limex — бетонозмішувачі українського виробництва.', 'Ремонтопридатні, запчастини є завжди.'],
            ['altrad', 'Altrad', 'Франція', '#004b87', 'Altrad — редукторні бетонозмішувачі для великих об\'ємів.', 'Тримає безперервну роботу на фундаменті.'],
            ['enar', 'Enar', 'Іспанія', '#e30613', 'Enar — глибинні вібратори для бетону.', 'Без вібратора у фундаменті лишаються порожнини.'],
            ['trotec', 'Trotec', 'Німеччина', '#e30613', 'Trotec — осушувачі й теплові гармати.', 'Після затоплення осушувач важливіший за ремонт.'],
            ['alko', 'AL-KO', 'Німеччина', '#f39200', 'AL-KO — садова техніка для догляду за газоном.', 'Аератор беруть двічі на рік — купувати його немає сенсу.'],
            ['flir', 'FLIR', 'США', '#000000', 'FLIR — тепловізори для будівельної діагностики.', 'День оренди дешевший за одну помилку в утепленні.'],
        ])->mapWithKeys(fn ($b) => [$b[0] => Brand::create([
            'slug' => $b[0], 'name' => $b[1], 'country' => $b[2],
            'accent_color' => $b[3], 'about' => $b[4], 'why' => $b[5],
        ])]);
    }

    /** @return Collection<string, Category> */
    private function categories()
    {
        return collect(require database_path('seeders/data/categories.php'))
            ->mapWithKeys(function (array $c, int $i) {
                [$slug, $name, $genitive, $count, $heavy, $lead, $filters, $seo] = $c;

                return [$slug => Category::create([
                    'slug' => $slug, 'name' => $name, 'name_genitive' => $genitive,
                    'products_count' => $count, 'heavy' => $heavy, 'lead' => $lead,
                    'filter_specs' => $filters, 'seo_text' => $seo, 'position' => $i,
                ])];
            });
    }

    /** @return Collection<string, Category> */
    private function subcategories($cats)
    {
        $tree = [
            'perforatory' => [
                ['sds-plus', 'SDS-plus', 14],
                ['sds-max', 'SDS-max', 8],
                ['vidbiyni', 'Відбійні молотки', 11],
                ['betonolomy', 'Бетоноломи', 5],
                ['akumulyatorni-perforatory', 'Акумуляторні', 6],
            ],
            'vibroplyty' => [
                ['vibroplyty-lehki', 'Легкі до 100 кг', 7],
                ['vibroplyty-reversyvni', 'Реверсивні від 160 кг', 6],
                ['trambovky', 'Вібротрамбовки', 4],
                ['hlybynni-vibratory', 'Глибинні вібратори', 2],
            ],
            'klimat' => [
                ['pylososy', 'Будівельні пилососи', 9],
                ['osushuvachi', 'Осушувачі', 8],
                ['obihriv', 'Теплові гармати', 7],
            ],
        ];

        $made = collect();

        foreach ($tree as $parent => $children) {
            foreach ($children as $i => [$slug, $name, $count]) {
                $made[$slug] = Category::create([
                    'parent_id' => $cats[$parent]->id,
                    'slug' => $slug, 'name' => $name,
                    'products_count' => $count, 'position' => $i,
                ]);
            }
        }

        return $made;
    }

    /** @return array<string, Product> */
    private function products($brands, $cats, $subs): array
    {
        $made = [];

        foreach (require database_path('seeders/data/products.php') as $row) {
            $category = $row['sub'] ? $subs[$row['sub']] : $cats[$row['category']];

            $product = Product::create([
                'brand_id' => $brands[$row['brand']]->id,
                'category_id' => $category->id,
                'slug' => $row['slug'], 'name' => $row['name'], 'sku' => $row['sku'],
                'lead' => $row['lead'], 'description' => $row['description'],
                'specs' => $row['specs'], 'key_specs' => $row['key'],
                'kit' => $row['kit'], 'not_included' => $row['not_included'],
                'deposit' => $row['deposit'], 'base_price' => $row['base'],
                'retail_price' => $row['retail'], 'weight_kg' => $row['weight'],
                'rating' => $row['rating'], 'reviews_count' => $row['reviews'],
                'popularity' => $row['popularity'],
                'seo_text' => $row['seo'] ?? null,
                'manual_url' => '/files/manuals/'.$row['slug'].'.pdf',
            ]);

            // Сходинка: базовий рівень, −17% від 3 днів, −31% від 7.
            // Крок узятий з ринку: конкуренти дають ~10% за тиждень і до 40%
            // за місяць, ми показуємо знижку одразу в ціні, а не за запитом.
            foreach ([
                ['1–2 дні', 1, 2, $row['base'], 'базовий тариф'],
                ['3–6 днів', 3, 6, (int) (round($row['base'] * 0.83 / 10) * 10), '−17%'],
                ['від 7 днів', 7, null, (int) (round($row['base'] * 0.69 / 10) * 10), '−31%'],
            ] as [$label, $min, $max, $price, $note]) {
                $product->tiers()->create([
                    'label' => $label, 'min_days' => $min, 'max_days' => $max,
                    'price' => $price, 'note' => $note,
                ]);
            }

            $made[$row['slug']] = $product;
        }

        return $made;
    }

    /**
     * Кількість позицій у меню має збігатися з тим, що людина побачить,
     * відкривши категорію. Рахуємо власні товари плюс товари підкатегорій.
     */
    private function recountCategories(): void
    {
        Category::query()->with('children')->get()->each(function (Category $category) {
            $own = Product::where('category_id', $category->id)->count();
            $inChildren = Product::whereIn('category_id', $category->children->pluck('id'))->count();

            $category->update(['products_count' => $own + $inChildren]);
        });
    }

    /** @return Collection<string, Extra> */
    private function extras($cats)
    {
        return collect([
            ['bur-sds-plus-8', 'Бур SDS-plus 8×160 мм', 'купівля, шт', 120, 'perforatory'],
            ['bur-sds-plus-10', 'Бур SDS-plus 10×210 мм', 'купівля, шт', 140, 'perforatory'],
            ['bur-sds-max-24', 'Бур SDS-max 24×400 мм', 'купівля, шт', 480, 'perforatory'],
            ['zubylo-sds-plus', 'Зубило пласке SDS-plus', 'купівля, шт', 180, 'perforatory'],
            ['pika-sds-max', 'Піка SDS-max 400 мм', 'купівля, шт', 520, 'perforatory'],
            ['koronka-68', 'Коронка 68 мм під розетку', 'купівля, шт', 420, 'perforatory'],
            ['dysk-keramika-125', 'Диск по кераміці 125 мм', 'купівля, шт', 180, 'shlifuvalni'],
            ['dysk-beton-230', 'Алмазний диск по бетону 230 мм', 'купівля, шт', 640, 'shlifuvalni'],
            ['dysk-beton-350', 'Алмазний диск 350 мм для бензоріза', 'купівля, шт', 1850, 'pyly'],
            ['koronka-almaz-112', 'Алмазна коронка 112 мм', 'купівля, шт', 2400, 'pyly'],
            ['shlifkola-225', 'Шліфкола 225 мм, 5 шт', 'купівля, пачка', 320, 'shlifuvalni'],
            ['khrestyky-2', 'Хрестики 2 мм, 200 шт', 'купівля, пачка', 60, null],
            ['svp-100', 'Система СВП, 100 шт', 'купівля, пачка', 340, null],
            ['mishky-pylosos', 'Мішки для пилососа, 5 шт', 'купівля, пачка', 190, 'klimat'],
            ['lantsyug-35', 'Ланцюг для пили 35 см', 'купівля, шт', 380, 'sadova'],
            ['liska-3mm', 'Ліска для мотокоси 3 мм, 15 м', 'купівля, моток', 220, 'sadova'],
            ['elektrody-3', 'Електроди 3 мм, 2,5 кг', 'купівля, пачка', 340, 'zvaryuvalne'],
            ['drit-08', 'Зварювальний дріт 0,8 мм, 5 кг', 'купівля, котушка', 690, 'zvaryuvalne'],
        ])->mapWithKeys(fn ($x) => [$x[0] => Extra::create([
            'slug' => $x[0], 'name' => $x[1], 'sub' => $x[2], 'price' => $x[3],
            'category_id' => $x[4] ? $cats[$x[4]]->id : null,
        ])]);
    }

    private function attachExtras(array $made, $extras): void
    {
        $map = [
            'bosch-gbh-2-26-dre' => ['bur-sds-plus-8', 'bur-sds-plus-10', 'zubylo-sds-plus'],
            'bosch-gbh-2-28-f' => ['bur-sds-plus-8', 'bur-sds-plus-10', 'koronka-68'],
            'makita-hr2470' => ['bur-sds-plus-8', 'zubylo-sds-plus', 'koronka-68'],
            'makita-dhr243' => ['bur-sds-plus-8', 'bur-sds-plus-10'],
            'makita-hr4013c' => ['bur-sds-max-24', 'pika-sds-max'],
            'makita-hm1203c' => ['pika-sds-max'],
            'bosch-gsh-16-30' => ['pika-sds-max'],
            'makita-sg1251j' => ['dysk-keramika-125', 'mishky-pylosos'],
            'husqvarna-k770' => ['dysk-beton-350'],
            'husqvarna-dm220' => ['koronka-almaz-112'],
            'karcher-wd-6' => ['mishky-pylosos'],
            'starmix-ism-m' => ['mishky-pylosos'],
            'bosch-gws-22-230' => ['dysk-beton-230'],
            'makita-9558hn' => ['dysk-keramika-125'],
            'flex-ge-5-r' => ['shlifkola-225'],
            'husqvarna-236' => ['lantsyug-35'],
            'husqvarna-128r' => ['liska-3mm'],
            'paton-vdi-250' => ['elektrody-3'],
            'paton-promig-250' => ['drit-08'],
        ];

        foreach ($map as $slug => $list) {
            $made[$slug]->extras()->attach(
                collect($list)->mapWithKeys(fn ($e, $i) => [$extras[$e]->id => ['position' => $i]])->all()
            );
        }
    }

    private function relate(array $made): void
    {
        // «З цим орендують» — те, що реально беруть у пару, а не «схожі товари».
        $relations = [
            'bosch-gbh-2-26-dre' => [
                'with' => ['makita-sg1251j', 'starmix-ism-m', 'krause-3x9', 'bosch-d-tect-120'],
                'similar' => ['bosch-gbh-2-28-f', 'makita-hr2470'],
            ],
            'makita-hr2470' => [
                'with' => ['starmix-ism-m', 'bosch-d-tect-120'],
                'similar' => ['bosch-gbh-2-26-dre', 'makita-dhr243'],
            ],
            'makita-sg1251j' => [
                'with' => ['starmix-ism-m', 'bosch-gbh-2-26-dre'],
                'similar' => ['bosch-gws-22-230'],
            ],
            'makita-hm1203c' => [
                'with' => ['starmix-ism-m', 'bosch-gws-22-230'],
                'similar' => ['bosch-gsh-16-30', 'makita-hr4013c'],
            ],
            'wacker-dpu-2540' => [
                'with' => ['limex-165', 'husqvarna-k770'],
                'similar' => ['wacker-bp-1050', 'wacker-bs-60-2'],
            ],
            'wacker-bp-1050' => [
                'with' => ['limex-125'],
                'similar' => ['wacker-dpu-2540', 'wacker-bs-60-2'],
            ],
            'trotec-ttk-75' => [
                'with' => ['trotec-tds-30', 'karcher-wd-6'],
                'similar' => [],
            ],
            'honda-eu22i' => [
                'with' => ['trotec-tds-30'],
                'similar' => ['hyundai-hhy-7020fe'],
            ],
            'flex-ge-5-r' => [
                'with' => ['starmix-ism-m', 'krause-3x9'],
                'similar' => ['bosch-gbr-15-cag'],
            ],
            'husqvarna-128r' => [
                'with' => ['husqvarna-236', 'bosch-axt-25-tc'],
                'similar' => [],
            ],
        ];

        foreach ($relations as $slug => $kinds) {
            foreach ($kinds as $kind => $list) {
                foreach ($list as $i => $target) {
                    $made[$slug]->{$kind === 'with' ? 'related' : 'similar'}()
                        ->attach($made[$target]->id, ['kind' => $kind, 'position' => $i]);
                }
            }
        }
    }

    private function kits(array $made): void
    {
        foreach (require database_path('seeders/data/kits.php') as $i => $row) {
            $kit = Kit::create([
                'slug' => $row['slug'], 'name' => $row['name'], 'task' => $row['task'],
                'lead' => $row['lead'], 'discount_percent' => $row['discount'],
                'guide' => $row['guide'] ?? null, 'guide_url' => '/blog/'.$row['slug'],
                'position' => $i,
            ]);

            foreach ($row['items'] as $j => [$productSlug, $why, $optional]) {
                KitItem::create([
                    'kit_id' => $kit->id,
                    'product_id' => $made[$productSlug]->id,
                    'why' => $why, 'optional' => $optional, 'position' => $j,
                ]);
            }
        }
    }
}
