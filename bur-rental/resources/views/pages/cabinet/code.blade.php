@extends('layouts.app')

@section('title', 'Код підтвердження — БУР')

@section('content')
    <div class="container-bur max-w-[480px]">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Кабінет' => route('cabinet.login'), 'Код' => null]" />

        <h1 class="t-h1">Код із SMS</h1>
        <p class="mt-2 text-sm text-text-2">
            Надіслали на <span class="font-mono font-semibold text-text-1">{{ $phone }}</span>.
            Код діє {{ \App\Services\Clients\LoginCodes::LIFETIME_MINUTES }} хвилин.
        </p>

        @if (session('code_hint'))
            {{-- Тільки тестовий майданчик: там SMS не ходять. --}}
            <p class="mt-4 rounded-[12px] border border-warning-border bg-warning-bg p-4 text-sm text-warning-text">
                Тестовий режим: код <span class="font-mono font-bold">{{ session('code_hint') }}</span>.
                На бойовому сайті він приходить у SMS.
            </p>
        @endif

        <form method="post" action="{{ route('cabinet.verify') }}"
              class="mt-6 rounded-[12px] border border-border-1 bg-surface-0 p-6">
            @csrf

            <x-field name="code" label="Код" type="text" required
                     inputmode="numeric" autocomplete="one-time-code" maxlength="6"
                     class="h-14 text-center font-mono text-2xl tracking-[0.3em]"
                     :help="$attemptsLeft > 0 ? 'Залишилось спроб: '.$attemptsLeft : null" />

            <button type="submit"
                    class="mt-5 h-12 w-full cursor-pointer rounded-[6px] bg-brand text-[15px] font-semibold text-white hover:bg-brand-hover">
                Увійти
            </button>
        </form>

        <form method="post" action="{{ route('cabinet.request-code') }}" class="mt-4">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <button type="submit" class="cursor-pointer text-sm font-semibold text-brand">
                Надіслати код ще раз
            </button>
        </form>
    </div>
@endsection
