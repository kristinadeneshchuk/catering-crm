@extends('layouts.app')

@section('title', 'Повернення і застава — БУР')
@section('description', 'Як здати інструмент, продовжити оренду, що буде при поломці або втраті, як скасувати бронь.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Повернення' => null]" />
        <h1 class="t-h1">Повернення і застава</h1>

        <x-section title="Як здати">
            <ol class="grid gap-3 md:grid-cols-3">
                @foreach ([
                    'Привезіть у філію до закриття' => 'Останній прийом техніки — за 30 хвилин до закриття.',
                    'Перевіряємо разом' => 'Огляд при вас, як і при видачі. Зауваження фіксуємо в акті.',
                    'Повертаємо заставу' => 'Одразу — готівкою або на картку, тим самим способом.',
                ] as $title => $text)
                    <li class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <div class="font-mono text-2xl font-bold text-brand">{{ $loop->iteration }}</div>
                        <div class="mt-1 text-[15px] font-semibold">{{ $title }}</div>
                        <p class="mt-1 text-[13px] leading-[20px] text-text-2">{{ $text }}</p>
                    </li>
                @endforeach
            </ol>
        </x-section>

        <x-section title="Три сценарії поломки" lead="Правила прописані заздалегідь, щоб не домовлятися на емоціях.">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-[12px] border border-success-border bg-success-bg p-5">
                    <h3 class="t-h3 text-success-text">Природний знос</h3>
                    <p class="mt-1 text-sm leading-[22px] text-text-2">
                        Наш клопіт. Привозимо заміну по місту за 2 години або повертаємо гроші
                        за невикористані дні.
                    </p>
                </div>
                <div class="rounded-[12px] border border-warning-border bg-warning-bg p-5">
                    <h3 class="t-h3 text-warning-text">Порушення правил</h3>
                    <p class="mt-1 text-sm leading-[22px] text-text-2">
                        Ремонт за ваш рахунок за калькуляцією сервісу. Показуємо деталі й акт робіт.
                    </p>
                </div>
                <div class="rounded-[12px] border border-danger-border bg-danger-bg p-5">
                    <h3 class="t-h3 text-danger-text">Втрата або крадіжка</h3>
                    <p class="mt-1 text-sm leading-[22px] text-text-2">
                        Відшкодування за залишковою вартістю з урахуванням зносу, а не за ціною нового.
                    </p>
                </div>
            </div>
        </x-section>

        <x-section title="Прострочення, скасування, дострокова здача">
            <div class="grid gap-3 sm:grid-cols-3">
                @foreach ([
                    'Прострочення' => 'Кожна почата доба — за базовим тарифом (без знижки за строк).',
                    'Скасування броні' => 'Безкоштовно до дати видачі. Оплату повертаємо повністю.',
                    'Дострокова здача' => 'Перерахуємо за фактичним строком і повернемо різницю.',
                ] as $title => $text)
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                        <h3 class="text-[15px] font-semibold">{{ $title }}</h3>
                        <p class="mt-1 text-[13px] leading-[20px] text-text-2">{{ $text }}</p>
                    </div>
                @endforeach
            </div>
        </x-section>

        <x-faq-list :faqs="$faqs" title="Питання про повернення" />
    </div>
@endsection
