{{--
    Розмітка сайту цілком: хто ми і як нас шукати всередині.

    Organization зшиває сайт із карткою компанії в пошуку, SearchAction дає
    рядок пошуку прямо у видачі за брендовим запитом. Обидві — один раз на
    сторінку, тому живуть у layout, а не в шаблонах.
--}}
@php
    $site = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => url('/').'#organization',
                'name' => 'БУР',
                'description' => 'Подобова оренда будівельного, садового та вимірювального інструменту.',
                'url' => url('/'),
                'telephone' => $city->phone ?? null,
                'areaServed' => $cities ?? ['Київ', 'Харків', 'Львів'],
            ],
            [
                '@type' => 'WebSite',
                '@id' => url('/').'#website',
                'url' => url('/'),
                'name' => 'БУР',
                'inLanguage' => 'uk-UA',
                'publisher' => ['@id' => url('/').'#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => route('search').'?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ],
    ];
@endphp

<script type="application/ld+json">{!! json_encode($site, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
