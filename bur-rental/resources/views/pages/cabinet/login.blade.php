@extends('layouts.app')

@section('title', 'Вхід у кабінет — БУР')
@section('description', 'Вхід у кабінет БУР за номером телефону: історія оренд, строки повернення, обране.')

@section('content')
    <div class="container-bur max-w-[480px]">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Кабінет' => null]" />

        <h1 class="t-h1">Вхід у кабінет</h1>
        <p class="mt-2 text-sm text-text-2">
            Введіть номер, з якого бронювали. Надішлемо код у SMS — пароль вигадувати не треба.
        </p>

        <form method="post" action="{{ route('cabinet.request-code') }}"
              class="mt-6 rounded-[12px] border border-border-1 bg-surface-0 p-6">
            @csrf

            <x-field name="phone" label="Телефон" type="tel" :value="$phone"
                     placeholder="+380 XX XXX XX XX" required
                     help="Той самий номер, який вказували в брони — історія замовлень підтягнеться сама." />

            <button type="submit"
                    class="mt-5 h-12 w-full cursor-pointer rounded-[6px] bg-brand text-[15px] font-semibold text-white hover:bg-brand-hover">
                Отримати код
            </button>
        </form>

        <p class="mt-4 text-[13px] text-text-3">
            Немає жодної броні? Кабінет усе одно відкриється — просто буде порожній.
            <a href="{{ route('catalog.index') }}">Почніть з каталогу</a>.
        </p>
    </div>
@endsection
