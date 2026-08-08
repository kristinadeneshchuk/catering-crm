@extends('layouts.app')

@section('title', 'Оренда інструменту для юросіб і ФОП — БУР')
@section('description', 'Безготівка, рахунок і акти, відстрочка до 30 днів, оренда без застави, персональний менеджер.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Для юросіб' => null]" />

        <div class="grid gap-6 md:[grid-template-columns:1fr_400px]">
            <div>
                <h1 class="t-h1">Оренда для юросіб і ФОП</h1>
                <p class="mt-3 max-w-[680px] text-[18px] leading-[28px] text-text-2">
                    Для прорабів і будівельних компаній: техніка пакетами на об'єкт, документи того ж дня,
                    оплата за фактом.
                </p>

                <x-section title="Умови">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ([
                            'Безготівковий розрахунок' => 'Рахунок за ЄДРПОУ, акт і договір — на email того ж дня.',
                            'Відстрочка до 30 днів' => 'Після третьої оренди, за договором.',
                            'Оренда без застави' => 'Відповідальність фіксується договором, гроші не заморожуються.',
                            'Персональний менеджер' => 'Один контакт на всі об\'єкти, резерв техніки під графік робіт.',
                        ] as $title => $text)
                            <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                                <h3 class="t-h3">{{ $title }}</h3>
                                <p class="mt-1 text-sm leading-[22px] text-text-2">{{ $text }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-section>

                <x-section title="Кейси">
                    <div class="space-y-3">
                        @foreach ([
                            ['ТОВ «Моноліт-Буд»', 'Пакет із 12 позицій на об\'єкт у Дарниці, оренда помісячно. Заміна техніки на об\'єкті за 2 години, документи раз на місяць одним пакетом.'],
                            ['Мережа кав\'ярень', 'Осушувачі й теплові гармати після затоплення — привезли за 3 години в неділю.'],
                            ['ФОП, оздоблення', 'Штроборізи й пилососи щотижня під нові квартири. Відстрочка 30 днів, оплата раз на місяць.'],
                        ] as [$who, $what])
                            <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                                <h3 class="text-[15px] font-semibold">{{ $who }}</h3>
                                <p class="mt-1 text-sm leading-[22px] text-text-2">{{ $what }}</p>
                            </div>
                        @endforeach
                    </div>
                </x-section>
            </div>

            <aside class="md:sticky md:top-[88px] md:self-start">
                <div class="rounded-[12px] border-2 border-brand bg-surface-0 p-5">
                    <h2 class="t-h3">Запит комерційної пропозиції</h2>
                    <p class="mt-1 text-[13px] text-text-3">Відповідаємо протягом робочого дня.</p>

                    @if (session('lead'))
                        <p class="mt-3 rounded-[6px] border border-success-border bg-success-bg p-3 text-sm text-success-text">
                            {{ session('lead') }}
                        </p>
                    @endif

                    <form method="post" action="{{ route('leads.store') }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="kind" value="b2b">
                        <input type="hidden" name="context" value="b2b">

                        <x-field name="company" label="Компанія" required />
                        <x-field name="edrpou" label="ЄДРПОУ" help="8 цифр" inputmode="numeric" maxlength="8" />
                        <x-field name="name" label="Контактна особа" />
                        <x-field name="phone" label="Телефон" type="tel" placeholder="+380 __ ___ __ __" required />
                        <x-field name="email" label="Email для рахунку" type="email" required />

                        <div>
                            <label for="f-message" class="mb-1 block text-[13px] font-medium text-text-2">Що потрібно</label>
                            <textarea id="f-message" name="message" rows="3" placeholder="Позиції, строк, об'єкт"
                                      class="w-full rounded-[6px] border border-border-1 p-3 text-[15px] outline-none focus:border-brand"></textarea>
                        </div>

                        <button type="submit" class="h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                            Отримати пропозицію
                        </button>
                    </form>
                </div>
            </aside>
        </div>

        <x-faq-list :faqs="$faqs" title="Питання від юросіб" />
    </div>
@endsection
