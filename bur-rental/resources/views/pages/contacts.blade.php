@extends('layouts.app')

@section('title', 'Контакти — БУР')
@section('description', 'Філії, телефони, менеджери, форма зв\'язку.')

@section('content')
    <div class="container-bur">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Контакти' => null]" />
        <h1 class="t-h1">Контакти</h1>

        <div class="mt-6 grid gap-6 md:[grid-template-columns:1fr_380px]">
            <div>
                <x-section title="Філії {{ $city->name_locative }}" class="mt-0">
                    <div class="grid gap-4 md:grid-cols-2">
                        @foreach ($city->branches as $branch)
                            <x-branch-card :branch="$branch" :city="$city" />
                        @endforeach
                    </div>
                </x-section>

                <x-section title="Інші міста">
                    <div class="flex flex-wrap gap-2">
                        @foreach ($allCities ?? \App\Models\City::orderBy('position')->get() as $other)
                            <a href="{{ route('city', $other) }}"
                               class="inline-flex min-h-11 items-center gap-2 rounded-[6px] border border-border-1 bg-surface-0 px-3.5 text-sm no-underline hover:border-brand hover:no-underline">
                                {{ $other->name }}
                                <span class="font-mono text-xs text-text-3">{{ $other->phone }}</span>
                            </a>
                        @endforeach
                    </div>
                </x-section>

                <x-section title="Реквізити">
                    <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5 text-sm leading-[24px] text-text-2">
                        ТОВ «БУР Прокат»<br>
                        ЄДРПОУ 43215678<br>
                        вул. Здолбунівська 7Г, Київ, 02081<br>
                        <a href="{{ route('terms') }}">Договір оферти</a>
                    </div>
                </x-section>
            </div>

            <aside class="md:sticky md:top-[88px] md:self-start">
                <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                    <h2 class="t-h3">Написати нам</h2>

                    @if (session('lead'))
                        <p class="mt-3 rounded-[6px] border border-success-border bg-success-bg p-3 text-sm text-success-text">
                            {{ session('lead') }}
                        </p>
                    @endif

                    <form method="post" action="{{ route('leads.store') }}" class="mt-3 space-y-3">
                        @csrf
                        <input type="hidden" name="kind" value="contact">
                        <input type="hidden" name="context" value="contacts">

                        <x-field name="name" label="Як до вас звертатись" />
                        <x-field name="phone" label="Телефон" type="tel" placeholder="+380 __ ___ __ __" required />

                        <div>
                            <label for="f-message" class="mb-1 block text-[13px] font-medium text-text-2">Питання</label>
                            <textarea id="f-message" name="message" rows="4"
                                      class="w-full rounded-[6px] border border-border-1 p-3 text-[15px] outline-none focus:border-brand"></textarea>
                        </div>

                        <button type="submit" class="h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                            Надіслати
                        </button>
                    </form>

                    <div class="mt-4 border-t border-border-1 pt-4 text-sm">
                        <div class="font-mono text-lg font-semibold">{{ $city->phone }}</div>
                        <div class="text-text-3">щодня 8:00–20:00</div>
                    </div>
                </div>
            </aside>
        </div>
    </div>
@endsection
