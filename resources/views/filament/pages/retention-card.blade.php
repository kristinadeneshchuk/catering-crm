@php
    $order = $record->order;
    $client = $record->client;
    
    if (!$order || !$client) return;

    $endDate = \Carbon\Carbon::parse($order->end_date);
    $diff = now()->startOfDay()->diffInDays($endDate->startOfDay(), false);

    // Нова логіка: колір тексту/бордюру та легкий фон
    $color = ''; $bg = ''; $badgeText = '';

    if ($diff > 0) {
        $color = '#3b82f6'; // Синій
        $bg = 'rgba(59, 130, 246, 0.1)'; 
        $badgeText = "⚡ Залишилось {$diff} дн.";
    } elseif ($diff == 0) {
        $color = '#ef4444'; // Червоний
        $bg = 'rgba(239, 68, 68, 0.1)';
        $badgeText = "🔥 ОСТАННІЙ ДЕНЬ";
    } else {
        $color = '#f97316'; // Помаранчевий
        $bg = 'rgba(249, 115, 22, 0.1)';
        $badgeText = "⚠️ Відвалився " . abs($diff) . " дн. тому";
    }
@endphp

<div 
    id="{{ $record->id }}"
    wire:click="recordClicked('{{ $record->id }}', {{ $record }})"
    class="p-4 bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-gray-800 cursor-pointer transition-all hover:ring-1 hover:ring-primary-500"
    style="border-left: 2px solid {{ $color }};"
>
    <div class="flex justify-between items-start mb-3">
        <h3 class="font-bold text-sm text-gray-900 dark:text-white leading-tight">
            {{ $client->name ?? 'Без імені' }}
        </h3>
    </div>

    {{-- 🔥 ОНОВЛЕНИЙ ДИЗАЙН БЕЙДЖА: Тонкий і легкий --}}
    <div class="mb-3">
        <span class="text-[10px] px-2 py-0.5 rounded border font-medium uppercase tracking-wider" 
              style="background-color: {{ $bg }}; color: {{ $color }}; border-color: {{ $color }};">
            {{ $badgeText }}
        </span>
    </div>

    {{-- Контактна інформація --}}
    <div class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-1">
        <x-filament::icon icon="heroicon-m-phone" class="w-4 h-4 text-gray-400" />
        <a href="tel:{{ $client->phone }}" class="hover:underline text-primary-600" @click.stop>{{ $client->phone ?? 'Немає номеру' }}</a>
    </div>

    {{-- Раціон --}}
    <div class="text-[11px] text-gray-500 dark:text-gray-400 flex flex-col gap-1">
        <div class="flex justify-between border-b border-gray-100 dark:border-gray-800 pb-1">
            <span>Раціон:</span>
            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $order->calories }} ккал</span>
        </div>
        <div class="flex justify-between pt-1">
            <span>Закінчення:</span>
            <span class="font-semibold text-gray-800 dark:text-gray-200">{{ $endDate->format('d.m.Y') }}</span>
        </div>
    </div>

    @if($record->comment)
        <div class="mt-3 text-[11px] italic text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-800 pt-2 line-clamp-2">
            💬 {{ $record->comment }}
        </div>
    @endif
</div>