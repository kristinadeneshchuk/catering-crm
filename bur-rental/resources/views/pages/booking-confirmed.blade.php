@extends('layouts.app')

@section('title', 'Бронь '.$booking->number.' — БУР')

@section('content')
    <div class="container-bur max-w-[760px]">
        <div class="mt-8 rounded-[12px] border border-brand bg-brand-tint p-6 text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-brand text-white">
                <x-ui-icon name="check" class="size-6" />
            </div>
            <h1 class="t-h1 mt-3">Бронь підтверджено</h1>
            <p class="mt-1 font-mono text-lg font-bold">{{ $booking->number }}</p>
        </div>

        @if (session('taken'))
            <p class="mt-4 rounded-[12px] border border-warning-border bg-warning-bg p-4 text-sm text-warning-text">
                Увага: {{ implode(', ', session('taken')) }} — на ці дати позицію вже могли забрати.
                Менеджер зателефонує і запропонує найближчу вільну дату або заміну.
            </p>
        @endif

        <div class="mt-6 rounded-[12px] border border-border-1 bg-surface-0 p-6">
            <h2 class="t-h3">Коли і де забрати</h2>

            <dl class="mt-3 space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-text-2">Дата й час видачі</dt>
                    <dd class="text-right font-semibold">
                        {{ $booking->date_from->format('d.m.Y') }}, з 8:00
                        <span class="block font-mono text-xs font-normal text-text-3">
                            повернення до {{ $booking->date_to->format('d.m.Y') }}
                        </span>
                    </dd>
                </div>

                @if ($booking->branch)
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-2">Адреса філії</dt>
                        <dd class="text-right">
                            <span class="font-semibold">{{ $booking->branch->name }}</span>
                            <span class="block">{{ $booking->branch->address }}</span>
                            <a href="https://www.google.com/maps/search/{{ urlencode($booking->branch->address) }}" class="text-sm">
                                показати на карті →
                            </a>
                        </dd>
                    </div>
                @endif

                @if ($booking->address)
                    <div class="flex justify-between gap-4">
                        <dt class="text-text-2">Доставка</dt>
                        <dd class="text-right font-semibold">{{ $booking->address }}</dd>
                    </div>
                @endif
            </dl>

            <p class="mt-4 rounded-[6px] border border-border-1 bg-surface-1 p-3 text-[13px] text-text-2">
                Візьміть з собою паспорт або Дію
                @if ($booking->client_type === 'company')
                    та печатку/довіреність на отримання
                @endif
                — без документа видати не зможемо.
            </p>
        </div>

        <div class="mt-4 rounded-[12px] border border-border-1 bg-surface-0 p-6">
            <h2 class="t-h3">Що забронювали</h2>

            <table class="mt-3 w-full text-sm">
                <tbody>
                    @foreach ($booking->items as $item)
                        <tr class="border-b border-surface-2 last:border-0">
                            <th scope="row" class="py-2.5 text-left font-normal">
                                {{ $item->title }}
                                <span class="block text-xs text-text-3">
                                    {{ $item->qty }} шт · {{ $item->days }} дн · {{ $item->price_per_day }} ₴/день
                                </span>
                            </th>
                            <td class="py-2.5 text-right font-mono font-semibold">
                                {{ number_format($item->total, 0, ',', ' ') }} ₴
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4 space-y-2 border-t border-border-1 pt-4 text-sm">
                <div class="flex justify-between">
                    <span class="text-text-2">Оренда</span>
                    <span class="font-mono font-semibold">{{ number_format($booking->rent_total, 0, ',', ' ') }} ₴</span>
                </div>
                @if ($booking->extras_total)
                    <div class="flex justify-between">
                        <span class="text-text-2">Витратники</span>
                        <span class="font-mono font-semibold">{{ number_format($booking->extras_total, 0, ',', ' ') }} ₴</span>
                    </div>
                @endif
                @if ($booking->delivery_total)
                    <div class="flex justify-between">
                        <span class="text-text-2">Доставка</span>
                        <span class="font-mono font-semibold">{{ number_format($booking->delivery_total, 0, ',', ' ') }} ₴</span>
                    </div>
                @endif
                <div class="flex justify-between border-t border-border-1 pt-2">
                    <span class="text-text-2">Застава <span class="text-text-3">(повертається)</span></span>
                    <span class="font-mono font-semibold">{{ number_format($booking->deposit_total, 0, ',', ' ') }} ₴</span>
                </div>
                <div class="flex items-baseline justify-between border-t border-border-1 pt-2">
                    <span class="font-semibold">До сплати</span>
                    <span class="font-mono text-[26px] font-bold">{{ number_format($booking->payable, 0, ',', ' ') }} ₴</span>
                </div>
            </div>
        </div>

        <div class="mt-4 flex flex-wrap gap-3">
            <a href="{{ route('home') }}"
               class="flex h-13 flex-1 items-center justify-center rounded-[6px] bg-brand px-5 text-base font-semibold text-white no-underline hover:text-white hover:no-underline">
                На головну
            </a>
            <a href="{{ route('contacts') }}"
               class="flex h-13 flex-1 items-center justify-center rounded-[6px] border-[1.5px] border-text-1 px-5 text-base font-semibold text-text-1 no-underline hover:no-underline">
                Зв'язатися з менеджером
            </a>
        </div>
    </div>
@endsection
