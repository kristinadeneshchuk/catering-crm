@extends('layouts.app')

@section('title', 'Комплект «'.$kit->name.'» в оренду — БУР')
@section('description', Str::limit($kit->lead, 155))

@section('content')
    @php
        $itemsData = $kit->items->map(fn ($item) => [
            'id' => $item->product->id,
            'name' => $item->product->name,
            'brand' => $item->product->brand->name,
            'why' => $item->why,
            'optional' => $item->optional,
            'price' => $item->product->min_price,
            'base' => $item->product->base_price,
            'deposit' => $item->product->deposit,
            'url' => route('product', $item->product),
        ]);
    @endphp

    <div class="container-bur"
         x-data="{
            days: 7,
            off: {},
            discount: {{ $kit->discount_percent }},
            items: {{ Js::from($itemsData) }},
            get picked() { return this.items.filter(i => !this.off[i.id]) },
            get sum() { return this.picked.reduce((s, i) => s + i.price * this.days, 0) },
            get kitPrice() { return Math.round(this.sum * (100 - this.discount) / 100) },
            get save() { return this.sum - this.kitPrice },
            money(n) { return n.toLocaleString('uk-UA') + ' ₴' },
         }">

        <x-breadcrumbs :items="[
            'Головна' => route('home'),
            'Комплекти під задачу' => route('kits.index'),
            $kit->name => null,
        ]" />

        <h1 class="t-h1">Комплект «{{ $kit->name }}»</h1>
        <p class="mt-3 max-w-[760px] text-[15px] leading-[26px] text-text-2">{{ $kit->lead }}</p>

        <div class="mt-6 grid gap-6 md:[grid-template-columns:1fr_360px]">
            <div>
                <x-section title="Що входить">
                    <div class="space-y-2">
                        <template x-for="item in items" :key="item.id">
                            <label class="flex cursor-pointer items-center gap-3 rounded-[8px] border bg-surface-0 p-4"
                                   :class="off[item.id] ? 'border-border-1 opacity-60' : 'border-brand bg-brand-tint'">
                                <input type="checkbox" class="sr-only" :checked="!off[item.id]"
                                       @change="off[item.id] = !off[item.id]">
                                <span class="inline-flex size-5 shrink-0 items-center justify-center rounded-[4px]"
                                      :class="off[item.id] ? 'border-[1.5px] border-border-2 bg-white' : 'bg-brand'">
                                    <svg x-show="!off[item.id]" class="size-3 text-white" viewBox="0 0 24 24" fill="none"
                                         stroke="currentColor" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="m5 13 4 4L19 7" />
                                    </svg>
                                </span>
                                <span class="flex-1">
                                    <span class="block text-[11px] text-text-3" x-text="item.brand"></span>
                                    <a :href="item.url" class="block text-sm font-semibold text-text-1" x-text="item.name"></a>
                                    <span class="block text-[13px] text-text-3" x-text="item.why"></span>
                                </span>
                                <span class="text-right">
                                    <span class="block font-mono text-sm font-bold" x-text="item.price + ' ₴'"></span>
                                    <span class="block text-[11px] text-text-3">за день</span>
                                </span>
                            </label>
                        </template>
                    </div>
                </x-section>

                @if ($kit->guide)
                    <x-section title="Як це робити — коротко">
                        <ol class="space-y-3">
                            @foreach (preg_split('/\n/', trim($kit->guide)) as $step)
                                <li class="flex gap-3 rounded-[12px] border border-border-1 bg-surface-0 p-4">
                                    <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand-tint font-mono text-sm font-bold text-brand">
                                        {{ $loop->iteration }}
                                    </span>
                                    <p class="text-sm leading-[22px] text-text-2">{{ preg_replace('/^\d+\.\s*/', '', $step) }}</p>
                                </li>
                            @endforeach
                        </ol>
                        <a href="{{ $kit->guide_url }}" class="mt-3 inline-block text-sm font-semibold text-brand">
                            Повний гайд з розрахунком матеріалів →
                        </a>
                    </x-section>
                @endif

                <x-section title="Що ще знадобиться" lead="Витратники не входять в оренду — купуються на видачі.">
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($extras as $extra)
                            <div class="flex items-center justify-between rounded-[8px] border border-border-1 bg-surface-0 p-3.5 text-sm">
                                <span>{{ $extra->name }}</span>
                                <span class="font-mono font-bold">{{ $extra->price }} ₴</span>
                            </div>
                        @endforeach
                    </div>
                </x-section>
            </div>

            <aside class="md:sticky md:top-[88px] md:self-start">
                <div class="rounded-[12px] border border-border-1 bg-surface-0 p-5">
                    <h2 class="t-h3">Ваш комплект</h2>

                    <div class="mt-3">
                        <div class="flex items-baseline justify-between">
                            <label for="kit-days" class="text-sm font-medium">На скільки днів?</label>
                            <span class="font-mono text-sm font-semibold" x-text="days + ' дн.'"></span>
                        </div>
                        <input id="kit-days" type="range" min="1" max="30" x-model.number="days" class="days-range mt-2 w-full">
                        <div class="flex justify-between font-mono text-[11px] text-text-3"><span>1</span><span>7</span><span>14</span><span>30</span></div>
                    </div>

                    <div class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <span class="text-text-2">Окремими позиціями</span>
                            <span class="font-mono text-text-3 line-through" x-text="money(sum)"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-text-2">Ціна комплекту</span>
                            <span class="font-mono font-bold" x-text="money(kitPrice)"></span>
                        </div>
                        <div class="flex justify-between rounded-[6px] bg-success-bg px-3 py-2 text-success-text">
                            <span class="font-semibold">Економія</span>
                            <span class="font-mono font-bold" x-text="money(save)"></span>
                        </div>
                    </div>

                    <button type="button"
                            @click="picked.forEach(i => $store.booking.add({
                                id: i.id, name: i.name, brand: i.brand, price: i.price, days,
                                from: '{{ now()->toDateString() }}',
                                to: '{{ now()->addDays(6)->toDateString() }}',
                                deposit: i.deposit,
                            }))"
                            class="mt-4 h-13 w-full cursor-pointer rounded-[6px] bg-brand text-base font-semibold text-white hover:bg-brand-hover">
                        Забронювати комплект
                    </button>

                    <x-trust-lines class="mt-4 border-t border-border-1 pt-4" />
                </div>
            </aside>
        </div>

        <x-rent-vs-buy :rent-week="$kit->priceFor(7) * 7" :own-year="24000"
                       note="Купити цей комплект — від 24 000 ₴. Ремонт, сервіс і зберігання наші." />

        <x-section title="Інші комплекти">
            <div class="grid gap-4 [grid-template-columns:repeat(auto-fill,minmax(240px,1fr))]">
                @foreach ($others as $other)
                    <a href="{{ route('kit', $other) }}"
                       class="rounded-[12px] border border-border-1 bg-surface-0 p-5 no-underline hover:border-brand hover:no-underline">
                        <div class="t-h3 text-text-1">{{ $other->name }}</div>
                        <div class="mt-2 font-mono text-sm font-bold text-brand">
                            від {{ number_format($other->priceFor(7), 0, ',', ' ') }} ₴/день →
                        </div>
                    </a>
                @endforeach
            </div>
        </x-section>
    </div>
@endsection
