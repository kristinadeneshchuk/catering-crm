@extends('layouts.app')

@section('title', 'Умови оренди інструменту — БУР')
@section('description', 'Хто може орендувати, як рахується доба, застава, продовження, відповідальність за поломку.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Умови оренди' => null]" />
        <h1 class="t-h1">Умови оренди</h1>

        <div class="mt-6 grid gap-8 md:[grid-template-columns:220px_1fr]">
            {{-- Якірна навігація збоку: сторінку читають вибірково, а не підряд --}}
            <nav class="md:sticky md:top-[88px] md:self-start" aria-label="Розділи сторінки">
                <ul class="space-y-1 text-sm">
                    @foreach ([
                        'who' => 'Хто може орендувати',
                        'day' => 'Як рахується доба',
                        'deposit' => 'Застава',
                        'extend' => 'Продовження',
                        'rules' => 'Правила експлуатації',
                    ] as $anchor => $label)
                        <li>
                            <a href="#{{ $anchor }}" class="block rounded-[6px] px-3 py-2 text-text-2 no-underline hover:bg-surface-0 hover:text-brand">
                                {{ $label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>

            <div class="max-w-[760px] space-y-6">
                <section id="who" class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
                    <h2 class="t-h2">Хто може орендувати</h2>
                    <p class="mt-2 text-[15px] leading-[26px] text-text-2">
                        Фізособа від 18 років — за паспортом або Дією. Юрособа і ФОП — за договором,
                        рахунок виставляємо за ЄДРПОУ, застава не потрібна.
                    </p>
                </section>

                <section id="day" class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
                    <h2 class="t-h2">Як рахується доба</h2>
                    <p class="mt-2 text-[15px] leading-[26px] text-text-2">
                        Доба — 24 години з моменту видачі. Ціна за день падає зі строком:
                        1–2 дні — базовий тариф, 3–6 днів — мінус ~17%, від 7 днів — мінус ~31%.
                        Тариф перераховується за фактичним строком, у тому числі при достроковій здачі.
                    </p>
                </section>

                <section id="deposit" class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
                    <h2 class="t-h2">Застава</h2>
                    <p class="mt-2 text-[15px] leading-[26px] text-text-2">
                        Розмір залежить від класу техніки — від 600 до 4 000 ₴. Повертається повністю
                        одразу при поверненні справного інструменту, тим самим способом, яким вносилась.
                    </p>
                </section>

                <section id="extend" class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
                    <h2 class="t-h2">Продовження</h2>
                    <p class="mt-2 text-[15px] leading-[26px] text-text-2">
                        Дзвінком до кінця поточної доби — приїжджати не треба. Тариф перерахується
                        за новим строком: довше означає дешевше за день.
                    </p>
                </section>

                <section id="rules" class="rounded-[12px] border border-border-1 bg-surface-0 p-6">
                    <h2 class="t-h2">Правила експлуатації</h2>
                    <ul class="mt-2 space-y-2 text-[15px] leading-[26px] text-text-2">
                        <li>Використовувати за призначенням і в межах паспортних характеристик.</li>
                        <li>Не розбирати й не ремонтувати самостійно — при поломці телефонуйте нам.</li>
                        <li>Витратники (бури, диски, ланцюги) — ваші; знос оснастки не входить в оренду.</li>
                    </ul>
                </section>
            </div>
        </div>

        <x-faq-list :faqs="$faqs" title="Часті питання про умови" />
    </div>
@endsection
