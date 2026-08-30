@extends('layouts.app')

@section('title', 'Бронювання — БУР')
@section('description', 'Оформлення оренди: склад, дати, філія, доставка й оплата — одним екраном.')

@section('content')
    <div class="container-bur" x-data="bookingForm({ zones: {{ Js::from($zones->map->only(['slug', 'name', 'price', 'eta'])) }}, deposit: 0, discountPercent: {{ $discountPercent }}, client: {{ Js::from($client ? ['phone' => $client->display_phone, 'name' => $client->name, 'company' => $client->company, 'edrpou' => $client->edrpou, 'email' => $client->email] : null) }} })">
        <x-breadcrumbs :items="['Головна' => route('home'), 'Бронювання' => null]" />

        <h1 class="t-h1">Бронювання</h1>

        @if ($errors->any())
            <div class="mt-4 rounded-[12px] border border-danger-border bg-danger-bg p-4 text-sm text-danger-text">
                <p class="font-semibold">Перевірте форму:</p>
                <ul class="mt-1 list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="post" action="{{ route('booking.store') }}" @submit="submit($event)"
              class="mt-6 grid gap-6 md:[grid-template-columns:1fr_380px]">
            @csrf

            <div class="space-y-4">

                {{-- 1. Що і коли --}}
                <section class="rounded-[12px] border border-border-1 bg-surface-0">
                    <button type="button" @click="go(1)"
                            class="flex w-full cursor-pointer items-center gap-3 p-5 text-left">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand font-mono text-sm font-bold text-white">1</span>
                        <span class="t-h3">Що і коли</span>
                        <span class="ml-auto text-sm text-text-3" x-show="step !== 1">змінити</span>
                    </button>

                    <div x-show="step === 1" x-collapse>
                        <div class="border-t border-border-1 p-5">
                            <template x-if="!$store.booking.cart.length">
                                <p class="text-sm text-text-2">
                                    Кошик порожній. <a href="{{ route('catalog.index') }}">Оберіть інструмент →</a>
                                </p>
                            </template>

                            <template x-for="(item, i) in $store.booking.cart" :key="i">
                                <div class="flex flex-wrap items-center gap-3 border-b border-surface-2 py-3 last:border-0">
                                    <div class="size-12 shrink-0 rounded-[6px] bg-surface-2"></div>
                                    <div class="flex-1">
                                        <div class="text-sm font-semibold" x-text="item.name"></div>
                                        <div class="font-mono text-xs text-text-2">
                                            <span x-text="item.from"></span> — <span x-text="item.to"></span> ·
                                            <span x-text="item.days"></span> дн. × <span x-text="item.price"></span> ₴
                                        </div>
                                    </div>

                                    <input type="hidden" :name="`items[${i}][product_id]`" :value="item.id">
                                    <input type="hidden" :name="`items[${i}][qty]`" :value="item.qty">
                                    <input type="hidden" :name="`items[${i}][from]`" :value="item.from">
                                    <input type="hidden" :name="`items[${i}][to]`" :value="item.to">

                                    <span class="font-mono text-sm font-bold" x-text="(item.price * item.days * item.qty).toLocaleString('uk-UA') + ' ₴'"></span>
                                    <button type="button" class="cursor-pointer text-xs text-danger" @click="$store.booking.remove(i)">прибрати</button>
                                </div>
                            </template>

                            <fieldset class="mt-4 border-t border-surface-2 pt-4">
                                <legend class="text-[13px] font-semibold text-text-2">Філія видачі</legend>
                                <div class="mt-2 space-y-2">
                                    @foreach ($branches as $branch)
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[8px] border border-border-1 px-3 text-sm has-checked:border-brand has-checked:bg-brand-tint">
                                            <input type="radio" name="branch_id" value="{{ $branch->id }}"
                                                   @checked($loop->first) class="accent-[var(--color-brand)]">
                                            {{ $branch->name }}
                                            <span class="ml-auto text-xs text-text-3">{{ $branch->address }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <button type="button" @click="go(2)"
                                    class="mt-4 h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                                Далі — хто орендує
                            </button>
                        </div>
                    </div>
                </section>

                {{-- 2. Хто --}}
                <section class="rounded-[12px] border border-border-1 bg-surface-0">
                    <button type="button" @click="go(2)" class="flex w-full cursor-pointer items-center gap-3 p-5 text-left">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-sm font-bold"
                              :class="step >= 2 ? 'bg-brand text-white' : 'bg-surface-2 text-text-3'">2</span>
                        <span class="t-h3">Хто орендує</span>
                    </button>

                    <div x-show="step === 2" x-collapse x-cloak>
                        <div class="border-t border-border-1 p-5">
                            <div class="flex gap-2">
                                <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-[6px] border text-sm font-medium"
                                       :class="clientType === 'person' ? 'border-2 border-brand bg-brand-tint' : 'border-border-1'">
                                    <input type="radio" name="client_type" value="person" x-model="clientType" class="sr-only">
                                    Фізособа
                                </label>
                                <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-[6px] border text-sm font-medium"
                                       :class="clientType === 'company' ? 'border-2 border-brand bg-brand-tint' : 'border-border-1'">
                                    <input type="radio" name="client_type" value="company" x-model="clientType" class="sr-only">
                                    Юрособа / ФОП
                                </label>
                            </div>

                            <div class="mt-4 space-y-3">
                                <div>
                                    <label for="phone" class="mb-1 block text-[13px] font-medium text-text-2">Телефон *</label>
                                    <input id="phone" name="phone" type="tel" x-model="phone" @input="maskPhone()"
                                           placeholder="+380 __ ___ __ __"
                                           class="h-11 w-full rounded-[6px] border px-3 font-mono text-[15px] outline-none focus:border-brand"
                                           :class="errors.phone ? 'border-danger' : 'border-border-1'">
                                    <p x-show="errors.phone" x-text="errors.phone" class="mt-1 text-[13px] text-danger-text"></p>
                                </div>

                                <div x-show="clientType === 'person'">
                                    <label for="name" class="mb-1 block text-[13px] font-medium text-text-2">Ім'я *</label>
                                    <input id="name" name="name" x-model="name"
                                           class="h-11 w-full rounded-[6px] border px-3 text-[15px] outline-none focus:border-brand"
                                           :class="errors.name ? 'border-danger' : 'border-border-1'">
                                    <p x-show="errors.name" x-text="errors.name" class="mt-1 text-[13px] text-danger-text"></p>
                                </div>

                                <template x-if="clientType === 'company'">
                                    <div class="space-y-3">
                                        <div>
                                            <label for="company" class="mb-1 block text-[13px] font-medium text-text-2">Назва компанії *</label>
                                            <input id="company" name="company" x-model="company"
                                                   class="h-11 w-full rounded-[6px] border px-3 text-[15px] outline-none focus:border-brand"
                                                   :class="errors.company ? 'border-danger' : 'border-border-1'">
                                            <p x-show="errors.company" x-text="errors.company" class="mt-1 text-[13px] text-danger-text"></p>
                                        </div>
                                        <div>
                                            <label for="edrpou" class="mb-1 block text-[13px] font-medium text-text-2">ЄДРПОУ *</label>
                                            <input id="edrpou" name="edrpou" x-model="edrpou" inputmode="numeric" maxlength="8"
                                                   class="h-11 w-full rounded-[6px] border px-3 font-mono text-[15px] outline-none focus:border-brand"
                                                   :class="errors.edrpou ? 'border-danger' : 'border-border-1'">
                                            <p x-show="errors.edrpou" x-text="errors.edrpou" class="mt-1 text-[13px] text-danger-text"></p>
                                            <p x-show="!errors.edrpou" class="mt-1 text-[13px] text-text-3">8 цифр — за ним виставимо рахунок</p>
                                        </div>
                                        <div>
                                            <label for="email" class="mb-1 block text-[13px] font-medium text-text-2">Email для рахунку *</label>
                                            <input id="email" name="email" type="email" x-model="email"
                                                   class="h-11 w-full rounded-[6px] border px-3 text-[15px] outline-none focus:border-brand"
                                                   :class="errors.email ? 'border-danger' : 'border-border-1'">
                                            <p x-show="errors.email" x-text="errors.email" class="mt-1 text-[13px] text-danger-text"></p>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            <button type="button" @click="go(3)"
                                    class="mt-4 h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                                Далі — як забрати
                            </button>
                        </div>
                    </div>
                </section>

                {{-- 3. Як забрати і оплатити --}}
                <section class="rounded-[12px] border border-border-1 bg-surface-0">
                    <button type="button" @click="go(3)" class="flex w-full cursor-pointer items-center gap-3 p-5 text-left">
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full font-mono text-sm font-bold"
                              :class="step >= 3 ? 'bg-brand text-white' : 'bg-surface-2 text-text-3'">3</span>
                        <span class="t-h3">Як забрати і оплатити</span>
                    </button>

                    <div x-show="step === 3" x-collapse x-cloak>
                        <div class="border-t border-border-1 p-5">
                            <div class="flex gap-2">
                                <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-[6px] border text-sm font-medium"
                                       :class="pickup === 'self' ? 'border-2 border-brand bg-brand-tint' : 'border-border-1'">
                                    <input type="radio" name="fulfilment" value="self" x-model="pickup" class="sr-only">
                                    Самовивіз
                                </label>
                                <label class="flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-[6px] border text-sm font-medium"
                                       :class="pickup === 'delivery' ? 'border-2 border-brand bg-brand-tint' : 'border-border-1'">
                                    <input type="radio" name="fulfilment" value="delivery" x-model="pickup" class="sr-only">
                                    Доставка
                                </label>
                            </div>

                            <template x-if="pickup === 'delivery'">
                                <div class="mt-4 space-y-3">
                                    <div>
                                        <label for="zone" class="mb-1 block text-[13px] font-medium text-text-2">Зона доставки</label>
                                        <select id="zone" name="delivery_zone_id" x-model="zone"
                                                class="h-11 w-full rounded-[6px] border border-border-1 px-3 text-[15px]">
                                            @foreach ($zones as $zone)
                                                <option value="{{ $zone->id }}">{{ $zone->name }} — {{ $zone->price }} ₴ · {{ $zone->eta }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="address" class="mb-1 block text-[13px] font-medium text-text-2">Адреса *</label>
                                        <input id="address" name="address" x-model="address"
                                               class="h-11 w-full rounded-[6px] border px-3 text-[15px] outline-none focus:border-brand"
                                               :class="errors.address ? 'border-danger' : 'border-border-1'">
                                        <p x-show="errors.address" x-text="errors.address" class="mt-1 text-[13px] text-danger-text"></p>
                                    </div>
                                </div>
                            </template>

                            <fieldset class="mt-5 border-t border-surface-2 pt-4">
                                <legend class="text-[13px] font-semibold text-text-2">Спосіб оплати</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    @foreach (['card' => 'Картка онлайн', 'cash' => 'Готівка на видачі', 'invoice' => 'Рахунок для юросіб', 'parts' => 'Оплата частинами'] as $value => $label)
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[6px] border border-border-1 px-3 text-sm has-checked:border-brand has-checked:bg-brand-tint">
                                            <input type="radio" name="payment" value="{{ $value }}" x-model="payment" class="accent-[var(--color-brand)]">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <fieldset class="mt-5 border-t border-surface-2 pt-4">
                                <legend class="text-[13px] font-semibold text-text-2">Застава</legend>
                                <div class="mt-2 grid gap-2 sm:grid-cols-3">
                                    @foreach (['card-hold' => 'Заморозка на картці', 'cash' => 'Готівкою на видачі', 'none' => 'За договором (юрособи)'] as $value => $label)
                                        <label class="flex min-h-11 cursor-pointer items-center gap-2 rounded-[6px] border border-border-1 px-3 text-sm has-checked:border-brand has-checked:bg-brand-tint">
                                            <input type="radio" name="deposit_way" value="{{ $value }}" x-model="depositWay" class="accent-[var(--color-brand)]">
                                            {{ $label }}
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>

                            <div class="mt-5">
                                <label for="comment" class="mb-1 block text-[13px] font-medium text-text-2">Коментар</label>
                                <textarea id="comment" name="comment" rows="3"
                                          class="w-full rounded-[6px] border border-border-1 p-3 text-[15px] outline-none focus:border-brand"></textarea>
                            </div>

                            <button type="submit"
                                    class="mt-5 h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                                Забронювати
                            </button>
                            <p class="mt-2 text-center text-[13px] text-text-3">
                                Натискаючи, ви погоджуєтесь з <a href="{{ route('terms') }}">умовами оренди</a>.
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Sticky-підсумок: не змінюється при скролі, на мобільному — панель внизу --}}
            <aside class="md:sticky md:top-[88px] md:self-start">
                <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                    <h2 class="t-h3">Ваше замовлення</h2>

                    <div class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-text-2">Оренда</span>
                            <span class="font-mono font-semibold" x-text="$store.booking.total.toLocaleString('uk-UA') + ' ₴'"></span>
                        </div>
                        <div class="flex justify-between text-success-text" x-show="discountPercent > 0" x-cloak>
                            <span>Знижка постійного клієнта <span class="font-mono" x-text="'−' + discountPercent + '%'"></span></span>
                            <span class="font-mono font-semibold" x-text="'−' + discountAmount.toLocaleString('uk-UA') + ' ₴'"></span>
                        </div>
                        <div class="flex justify-between" x-show="pickup === 'delivery'">
                            <span class="text-text-2">Доставка</span>
                            <span class="font-mono font-semibold" x-text="deliveryPrice.toLocaleString('uk-UA') + ' ₴'"></span>
                        </div>
                        <div class="flex justify-between border-t border-border-1 pt-2">
                            <span class="text-text-2">Застава <span class="text-text-3">(повертається)</span></span>
                            <span class="font-mono font-semibold" x-text="$store.booking.deposit.toLocaleString('uk-UA') + ' ₴'"></span>
                        </div>
                    </div>

                    <div class="mt-3 flex items-baseline justify-between border-t border-border-1 pt-3">
                        <span class="text-sm font-semibold">До сплати зараз</span>
                        <span class="font-mono text-[26px] font-bold"
                              x-text="payable.toLocaleString('uk-UA') + ' ₴'"></span>
                    </div>

                    <x-trust-lines class="mt-4 border-t border-border-1 pt-4" />
                </div>
            </aside>
        </form>
    </div>
@endsection
