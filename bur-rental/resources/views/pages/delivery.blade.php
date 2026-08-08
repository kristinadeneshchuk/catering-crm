@extends('layouts.app')

@section('title', 'Доставка й оплата — БУР')
@section('description', 'Зони доставки з фіксованою ціною, доплати за важку техніку, способи оплати і внесення застави.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Доставка й оплата' => null]" />
        <h1 class="t-h1">Доставка й оплата</h1>

        <x-section title="Зони доставки {{ $city->name_locative }}">
            <div class="overflow-x-auto rounded-[12px] border border-border-1 bg-surface-0">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-border-1 text-left text-text-3">
                            <th class="p-4 font-normal">Зона</th>
                            <th class="p-4 font-normal">Вартість</th>
                            <th class="p-4 font-normal">Час у дорозі</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($zones as $zone)
                            <tr class="border-b border-surface-2 last:border-0">
                                <th scope="row" class="p-4 text-left font-medium">
                                    {{ $zone->name }}
                                    @if ($zone->note)
                                        <span class="block text-[13px] font-normal text-text-3">{{ $zone->note }}</span>
                                    @endif
                                </th>
                                <td class="p-4 font-mono font-bold">{{ $zone->price }} ₴</td>
                                <td class="p-4 text-text-2">{{ $zone->eta }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>

        <x-section title="Доплати за важку техніку" lead="Ціни фіксовані — на видачі сюрпризів не буде.">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach (['Гідроборт' => '+150 ₴', 'Техніка від 200 кг' => '+400 ₴', 'Підйом на поверх' => '50 ₴/поверх'] as $label => $price)
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <div class="t-price">{{ $price }}</div>
                        <div class="mt-1 text-sm text-text-2">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-[13px] text-text-3">
                Важку техніку (віброплити 100+ кг, риштування, бетонозмішувачі) при оренді від 7 днів
                привозимо й забираємо безкоштовно.
            </p>
        </x-section>

        <x-section title="Способи оплати">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ([
                    'Картка онлайн' => 'При бронюванні, з поверненням застави на ту саму картку.',
                    'Готівка на видачі' => 'Оплата й застава готівкою у філії.',
                    'Безготівка за рахунком' => 'Для юросіб і ФОП, з актом і договором.',
                    'Оплата частинами' => 'Для оренди від 5 000 ₴.',
                ] as $title => $text)
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <h3 class="text-[15px] font-semibold">{{ $title }}</h3>
                        <p class="mt-1 text-[13px] leading-[20px] text-text-2">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </x-section>

        <x-section title="Застави за класами техніки">
            <div class="overflow-x-auto rounded-[12px] border border-border-1 bg-surface-0">
                <table class="w-full min-w-[420px] text-sm">
                    <thead>
                        <tr class="border-b border-border-1 text-left text-text-3">
                            <th class="p-4 font-normal">Клас техніки</th>
                            <th class="p-4 font-normal">Застава</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ([
                            'Ручний електроінструмент' => '600 – 1 500 ₴',
                            'Важкий електроінструмент, міксери' => '1 200 – 3 000 ₴',
                            'Бензо- і садова техніка' => '1 500 – 3 500 ₴',
                            'Віброплити, генератори, компресори' => '2 500 – 4 000 ₴',
                        ] as $class => $range)
                            <tr class="border-b border-surface-2 last:border-0">
                                <th scope="row" class="p-4 text-left font-normal text-text-2">{{ $class }}</th>
                                <td class="p-4 font-mono font-semibold">{{ $range }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>

        <x-faq-list :faqs="$faqs" title="Питання про доставку й оплату" />
    </div>
@endsection
