@extends('layouts.app')

@section('title', 'Мої дані — БУР')

@section('content')
    <div class="container-bur max-w-[560px]">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Кабінет' => route('cabinet'), 'Мої дані' => null]" />

        <h1 class="t-h1">Мої дані</h1>
        <p class="mt-2 text-sm text-text-2">
            Підставляються у форму бронювання — щоб не набирати їх щоразу.
        </p>

        @if (session('saved'))
            <p class="mt-4 rounded-[12px] border border-success-border bg-success-bg p-4 text-sm text-success-text">
                Збережено.
            </p>
        @endif

        <form method="post" action="{{ route('cabinet.profile.update') }}"
              class="mt-6 space-y-4 rounded-[12px] border border-border-1 bg-surface-0 p-6">
            @csrf
            @method('put')

            <div>
                <span class="mb-1 block text-[13px] font-medium text-text-2">Телефон</span>
                <p class="font-mono text-[15px] font-semibold">{{ $client->display_phone }}</p>
                <p class="mt-1 text-[13px] text-text-3">
                    Номер — це логін, тому тут він не змінюється. Новий номер — новий вхід.
                </p>
            </div>

            <x-field name="name" label="Ім'я" :value="$client->name" placeholder="Як до вас звертатися" />
            <x-field name="email" label="Пошта" type="email" :value="$client->email"
                     help="Потрібна лише для рахунку й акта — на неї нічого не розсилаємо." />
            <x-field name="company" label="Компанія" :value="$client->company" placeholder="ТОВ «…», для безготівки" />
            <x-field name="edrpou" label="ЄДРПОУ" :value="$client->edrpou" inputmode="numeric" />

            <button type="submit"
                    class="h-12 w-full cursor-pointer rounded-[6px] bg-brand text-[15px] font-semibold text-white hover:bg-brand-hover">
                Зберегти
            </button>
        </form>
    </div>
@endsection
