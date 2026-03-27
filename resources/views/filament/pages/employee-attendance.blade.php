<x-filament-panels::page>
    <div class="space-y-6">
        <div class="flex items-center gap-4 p-4 bg-white dark:bg-gray-900 border dark:border-gray-800 rounded-xl shadow-sm">
            <input type="date" wire:model.live="date" wire:change="loadAttendance" 
                   class="border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-lg shadow-sm focus:ring-primary-500 text-sm">
            <p class="text-sm text-gray-500">Оберіть дату для заповнення табеля</p>
        </div>

        @if($dailyTotal > 0)
            <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-xl flex justify-between items-center transition-all">
                <div class="flex items-center gap-3 text-green-700 dark:text-green-400">
                    <div class="p-2 bg-green-100 dark:bg-green-800 rounded-full">
                        <x-heroicon-o-check-circle class="w-6 h-6" />
                    </div>
                    <div>
                        <p class="font-bold text-lg leading-none">Операція виконана ✓</p>
                        <p class="text-xs opacity-80">Зарплата за {{ \Carbon\Carbon::parse($date)->format('d.m.Y') }} нарахована в систему</p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[10px] uppercase font-black tracking-widest opacity-60">Разом по табелю:</p>
                    <p class="text-2xl font-black">₴ {{ number_format($dailyTotal, 0, '.', ' ') }}</p>
                </div>
            </div>
        @else
            <div class="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl flex items-center gap-3 text-amber-700 dark:text-amber-400">
                <x-heroicon-o-exclamation-triangle class="w-6 h-6" />
                <p class="text-sm font-medium">За цей день ще немає нарахувань. Відмітьте тих, хто працював, та натисніть кнопку внизу.</p>
            </div>
        @endif

        <div class="border dark:border-gray-800 rounded-xl overflow-hidden bg-white dark:bg-gray-900 shadow-sm">
            <table class="w-full text-left">
                <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700">
                    <tr>
                        <th class="p-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Співробітник</th>
                        <th class="p-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Посада</th>
                        <th class="p-4 text-sm font-semibold text-gray-700 dark:text-gray-200">Ставка за день</th>
                        <th class="p-4 text-sm font-semibold text-center text-gray-700 dark:text-gray-200">Вихід</th>
                    </tr>
                </thead>
                <tbody class="divide-y dark:divide-gray-800">
                    @forelse($attendance as $id => $data)
                    <tr class="{{ $data['present'] ? 'bg-primary-50/30 dark:bg-primary-900/10' : '' }}">
                        <td class="p-4">
                            <span class="font-medium text-gray-900 dark:text-white">{{ $data['name'] }}</span>
                        </td>
                        <td class="p-4">
                            <span class="text-xs px-2 py-1 rounded-full bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400">
                                {{ $data['position'] }}
                            </span>
                        </td>
                        <td class="p-4">
                            <div class="flex items-center gap-2">
                                <span class="text-gray-400">₴</span>
                                <input type="number" wire:model="attendance.{{ $id }}.rate" 
                                       class="w-24 border-gray-300 dark:border-gray-700 dark:bg-gray-800 rounded-md py-1 text-sm focus:ring-primary-500">
                            </div>
                        </td>
                        <td class="p-4 text-center">
                            <input type="checkbox" wire:model="attendance.{{ $id }}.present" 
                                   class="w-6 h-6 text-primary-600 rounded border-gray-300 focus:ring-primary-500">
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="p-8 text-center text-gray-500">
                            Співробітників не знайдено. Перевірте, чи вони активні в розділі «Співробітники».
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-filament::button wire:click="save" size="xl" class="w-full shadow-lg">
            Зберегти табель та нарахувати гроші на баланси
        </x-filament::button>
    </div>
</x-filament-panels::page>