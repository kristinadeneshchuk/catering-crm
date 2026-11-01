<?php

/*
| Звідки брати фото товарів: офіційні сторінки виробників. Команда
| products:fetch-images відкриває сторінку, бере головне фото (og:image,
| twitter:image або image з розмітки Product) і зберігає його собі;
| пробує посилання по черзі, доки одне не спрацює. Товари, у яких фото
| вже є, не чіпає.
|
| Посилання знайдені пошуком по офіційних доменах виробників (жовтень 2026).
| Самих сторінок хмарна сесія відкрити не могла — перевіряє їх уже сервер,
| і що не спрацювало, видно у звіті команди.
|
| Свідомо НЕМАЄ (фото — вручну в адмінці, Товари → Фото):
|   hyundai-hhy-7020fe, hyundai-t700 — офіційних сторінок моделей не знайдено;
|   fubag-b3600-50 — офіційний сайт лише .ru;
|   limex-125, limex-165, altrad-300 — сторінок моделей у виробника немає;
|   wacker-bp-1050 — знята з виробництва, сторінки немає;
|   vitals-mask — модель без точної назви;
|   vyshka-tura-6, ryshtuvannia-sektsiia — без бренду, потрібні власні фото;
|   enar-m5 — справжній M5 AFP працює від 42 В через перетворювач, а в
|     каталозі описаний як 2300 Вт / 220 В: спершу з'ясувати, що за модель.
|
| Де точної сторінки моделі немає, взято найближчу модель тієї ж серії з
| тим самим корпусом (позначено коментарем).
|
| [slug товару => [посилання, запасне посилання, ...]]
*/

return [
    // ——— Bosch Professional ———
    'bosch-gbh-2-26-dre' => [
        'https://www.bosch-professional.com/iq/en/products/gbh-2-26-dre-0611253703',
        'https://www.bosch-professional.com/om/en/products/gbh-2-26-dre-06112537P0',
    ],
    'bosch-gbh-2-28-f' => [
        'https://www.bosch-professional.com/gb/en/products/gbh-2-28-f-0611267661',
        'https://www.bosch-professional.com/gh/en/products/gbh-2-28-f-06112676P0',
    ],
    'bosch-gsh-16-30' => [
        'https://www.bosch-professional.com/sa/en/products/gsh-16-30-06113351P0',
        'https://www.bosch-professional.com/lb/en/products/gsh-16-30-0611335100',
    ],
    'bosch-gsb-21-2-re' => [
        'https://www.bosch-professional.com/om/en/products/gsb-21-2-re-060119C560',
        'https://www.bosch-professional.com/sa/en/products/gsb-21-2-re-060119C560',
    ],
    // Окремої сторінки GWS 22-230 JH немає — GWS 22-230 H того ж корпусу.
    'bosch-gws-22-230' => [
        'https://www.bosch-professional.com/middle-east/en/products/gws-22-230-h-0601882103',
    ],
    'bosch-gbr-15-cag' => [
        'https://www.bosch-professional.com/ua/uk/products/gbr-15-cag-0601776001',
        'https://www.bosch-professional.com/mz/en/products/gbr-15-cag-0601776001',
    ],
    'bosch-gsa-1300' => [
        'https://www.bosch-professional.com/mz/en/products/gsa-1300-pce-060164E200',
        'https://www.bosch-professional.com/fr/fr/products/gsa-1300-pce-060164E200',
    ],
    'bosch-gll-3-80' => [
        'https://www.bosch-professional.com/gb/en/products/gll-3-80-0601063S00',
        'https://www.bosch-professional.com/fr/fr/products/gll-3-80-0601063S00',
    ],
    'bosch-glm-50' => [
        'https://www.bosch-professional.com/lb/en/products/glm-50-27-c-0601072T00',
        'https://www.bosch-professional.com/br/pt/products/glm-50-27-c-0601072T00',
    ],
    'bosch-d-tect-120' => [
        'https://www.bosch-professional.com/gb/en/products/d-tect-120-wallscanner-0601081303',
        'https://www.bosch-professional.com/de/de/products/wallscanner-d-tect-120-0601081303',
    ],
    'bosch-axt-25-tc' => [
        'https://www.bosch-diy.com/za/en/p/axt-25-tc-060080330c',
        'https://www2.bosch-diy.com/gb/en/p/axt-25-tc-0600803371',
    ],

    // ——— Makita ———
    'makita-hr2470' => [
        'https://www.makita.de/product/hr2470.html',
        'https://www.makitauk.com/product/hr2470.html',
    ],
    'makita-dhr243' => [
        'https://www.makitauk.com/product/dhr243',
        'https://www.makita.de/product/dhr243.html',
    ],
    'makita-hr4013c' => [
        'https://www.makitauk.com/product/hr4013c.html',
        'https://makita.com.au/corded/corded-power-tools/drills-fastening/rotary-hammers/hr4013c-40mm-sds-max-rotary-hammer-2',
    ],
    'makita-hm1203c' => [
        'https://makitatools.com/products/details/HM1203C',
        'https://www.makitauk.com/product/hm1203c.html',
    ],
    'makita-dhp484' => [
        'https://www.makitauk.com/product/dhp484.html',
        'https://makitatools.com/products/details/XPH12Z',
    ],
    'makita-9558hn' => [
        'https://www.makitauk.com/product/9558hn.html',
        'https://www.makita.de/product/9558hn.html',
    ],
    'makita-sg1251j' => [
        'https://www.makitauk.com/product/sg1251j.html',
        'https://www.makita.de/product/sg1251j.html',
    ],
    'makita-hs7601' => [
        'https://www.makitauk.com/product/hs7601.html',
        'https://www.makita.de/product/hs7601.html',
    ],

    // ——— Husqvarna ———
    'husqvarna-k770' => [
        'https://www.husqvarna.com/ie/power-cutters/k770/',
        'https://www.husqvarnaconstruction.com/us/power-cutters/k770/',
    ],
    'husqvarna-dm220' => [
        'https://husqvarnacp.com/uk/machines/drill-motors/dm-220/966563501',
        'https://www.husqvarnaconstruction.com/us/concrete-core-drills/dm220/',
    ],
    'husqvarna-236' => [
        'https://www.husqvarna.com/uk/support/236/',
        'https://www.husqvarna.com/ie/support/236/',
    ],
    'husqvarna-128r' => [
        'https://husqvarna.com/ua/pidtrymka/husqvarna-128r',
        'https://www.husqvarna.com/ie/support/128r/',
    ],

    // ——— Wacker Neuson ———
    'wacker-dpu-2540' => [
        'https://shop.wackerneuson.com/estore/uk/en/Compaction/Vibratory-Plates/Reversible-Vibratory-Plates/Vibratory-Plate-DPU-2540H/p/5000610035',
    ],
    'wacker-bs-60-2' => [
        'https://shop.wackerneuson.com/estore/uk/en/Compaction/Vibratory-Rammers/2-Stroke-Rammer/280-11in-Shoe,-Vibratory-Ramme-r/p/5200000663',
        'https://shop.wackerneuson.com/estore/uk/en/Compaction/Vibratory-Rammers/2-Stroke-Rammer/Rammer-BS60-2-11in/p/5100030600',
    ],

    // ——— Інші бренди ———
    'collomix-xo-4' => [
        'https://www.collomix.com/en/collomix-products/collomixer-paddle-mixers/xo4r',
    ],
    // Базова модель серії: GE 5 R відрізняється лише поворотним сегментом голови.
    'flex-ge-5-r' => [
        'https://www.flex-tools.com/en/products/wall-and-ceiling-sanders/ge-5',
    ],
    'honda-eu22i' => [
        'https://www.honda.co.uk/industrial/products/generators/inverter/eu22i.html',
    ],
    'karcher-wd-6' => [
        'https://www.karcher.com/ie/en/home-and-garden/products/wd-6-p-premium-p16283780',
        'https://www.kaercher.com/int/home-garden/wet-and-dry-vacuum-cleaners/wd-6-p-s-v-30-8-35-t-16283800.html',
    ],
    'starmix-ism-m' => [
        'https://www.starmix.de/en/universal-vacuum-cleaner-ipulse-m-1635-safe',
    ],
    'trotec-ttk-75' => [
        'https://uk.trotec.com/shop/ttk-75-e-dehumidifier.html',
        'https://en.trotec.com/shop/luftentfeuchter-ttk-75-e.html',
    ],
    'trotec-tds-30' => [
        'https://en.trotec.com/shop/tds-30-electric-fan-heater.html',
    ],
    'flir-c5' => [
        'https://www.flir.com/products/c5/',
    ],
    'alko-combi-care-38' => [
        'https://www.al-ko.com/shop/uk/al-ko-electric-lawn-rake-scarifier-combi-care-38-e-comfort-incl-box.html',
    ],
    // ВДІ-250E — попередня назва лінійки ECO-250.
    'paton-vdi-250' => [
        'https://paton.ua/en/svarochnoe-oborudovanie/svarochnye-invertory-mma/zvaryuvalniy-aparat-paton-eco-250/',
    ],
    'paton-promig-250' => [
        'https://paton.ua/en/svarochnoe-oborudovanie/svarochnye-poluavtomaty/zvaryuvalniy-nap-vavtomat-paton-promig-250-15-2/',
    ],
    'krause-3x9' => [
        'https://www.krause-systems.de/detail/product/225-sprossen-vielzweckleiter-tribilo-3x9-sprossen.html',
    ],
];
