<x-filament-panels::page>
    <div class="flex flex-wrap items-end gap-4">
        <div>
            <label for="branch" class="block text-sm font-medium">Філія</label>
            <select id="branch" wire:model.live="branchId"
                    class="mt-1 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700">
                @foreach ($this->branches as $branch)
                    <option value="{{ $branch->id }}">{{ $branch->city->name }} — {{ $branch->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="category" class="block text-sm font-medium">Категорія</label>
            <select id="category" wire:model.live="categoryId"
                    class="mt-1 rounded-lg border-gray-300 text-sm dark:bg-gray-900 dark:border-gray-700">
                <option value="">Усі</option>
                @foreach ($this->categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-2">
            <x-filament::button wire:click="shiftDates(-7)" color="gray" size="sm">← тиждень</x-filament::button>
            <x-filament::button wire:click="today" color="gray" size="sm">Сьогодні</x-filament::button>
            <x-filament::button wire:click="shiftDates(7)" color="gray" size="sm">тиждень →</x-filament::button>
        </div>

        <div class="ml-auto flex items-center gap-4 text-xs">
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded-sm bg-green-100 ring-1 ring-green-300"></span> вільно
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded-sm bg-orange-100 ring-1 ring-orange-300"></span> частково зайнято
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded-sm bg-red-200 ring-1 ring-red-400"></span> розібрали повністю
            </span>
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-3 rounded-sm bg-amber-200 ring-1 ring-amber-400"></span> сервіс
            </span>
        </div>
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Число в клітинці — скільки екземплярів ще можна видати цього дня.
        Клік ставить блокування на сервіс, клік по сервісному — знімає.
        Орендовані екземпляри звільняє тільки приймання техніки в бронюванні.
    </p>

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-gray-200 dark:border-gray-700">
                    <th class="sticky left-0 z-10 min-w-56 bg-white p-3 text-left font-medium dark:bg-gray-900">
                        Модель
                    </th>
                    @foreach ($this->days as $day)
                        <th @class([
                                'p-1 text-center text-xs font-medium tabular-nums',
                                'bg-primary-50 dark:bg-primary-950' => $day->isToday(),
                                'text-gray-400' => $day->isWeekend(),
                            ])>
                            <span class="block">{{ $day->format('d.m') }}</span>
                            <span class="block text-[10px] font-normal text-gray-400">
                                {{ ['нд','пн','вт','ср','чт','пт','сб'][$day->dayOfWeek] }}
                            </span>
                        </th>
                    @endforeach
                </tr>
            </thead>

            <tbody>
                @forelse ($this->products as $product)
                    <tr class="border-b border-gray-100 last:border-0 dark:border-gray-800">
                        <th scope="row" class="sticky left-0 z-10 bg-white p-3 text-left font-normal dark:bg-gray-900">
                            <span class="block text-xs text-gray-400">{{ $product->brand->name }}</span>
                            <a href="{{ \App\Filament\Resources\Products\ProductResource::getUrl('edit', ['record' => $product]) }}"
                               class="font-medium hover:underline">{{ $product->name }}</a>
                        </th>

                        @foreach ($this->days as $day)
                            @php $cell = $this->cell($product, $day->toDateString()); @endphp
                            <td class="p-0.5 text-center">
                                <button type="button"
                                        wire:click="toggle({{ $product->id }}, '{{ $day->toDateString() }}')"
                                        wire:loading.attr="disabled"
                                        title="{{ $product->name }} · {{ $day->format('d.m.Y') }} · вільно {{ $cell['free'] }} з {{ $cell['stock'] }}"
                                        @class([
                                            'block h-8 w-full rounded-sm text-[11px] font-semibold tabular-nums ring-1 transition',
                                            'bg-green-50 text-green-700 ring-green-200 hover:bg-green-100' => $cell['state'] === 'free',
                                            'bg-orange-100 text-orange-700 ring-orange-300 hover:bg-orange-200' => $cell['state'] === 'partial',
                                            'bg-red-200 text-red-800 ring-red-400 cursor-not-allowed' => $cell['state'] === 'rented',
                                            'bg-amber-200 text-amber-800 ring-amber-400 hover:bg-amber-300' => $cell['state'] === 'service',
                                        ])>
                                    {{-- Число — скільки екземплярів ще можна видати цього дня. --}}
                                    {{ $cell['stock'] > 1 ? $cell['free'] : '' }}
                                    <span class="sr-only">
                                        вільно {{ $cell['free'] }} з {{ $cell['stock'] }}
                                    </span>
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ $this->days->count() + 1 }}" class="p-8 text-center text-gray-500">
                            У цій філії немає позицій. Додайте залишки на вкладці «Залишки по філіях» у товарі.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
