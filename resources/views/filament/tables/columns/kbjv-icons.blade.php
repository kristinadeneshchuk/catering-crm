<div class="flex items-end gap-1 h-6 px-2 min-w-[60px] bg-gray-50/5 rounded-sm">
    @php
        // Надійно отримуємо дані з моделі
        $p = (float)($record->proteins_100g ?? 0);
        $f = (float)($record->fats_100g ?? 0);
        $c = (float)($record->carbs_100g ?? 0);
        
        $total = $p + $f + $c;
        // Масштабуємо відносно найбільшого значення, щоб стовпчики були видимими
        $max = max($p, $f, $c, 0.1); 
    @endphp

    @if($total > 0)
        {{-- Білки - Червоний --}}
        <div class="bg-red-500 w-2.5 rounded-t-[2px] shadow-sm" 
             style="height: {{ ($p / $max) * 100 }}%" 
             title="Білки: {{ $p }}г"></div>
        
        {{-- Жири - Жовтий --}}
        <div class="bg-amber-400 w-2.5 rounded-t-[2px] shadow-sm" 
             style="height: {{ ($f / $max) * 100 }}%" 
             title="Жири: {{ $f }}г"></div>
        
        {{-- Вуглеводи - Зелений --}}
        <div class="bg-emerald-500 w-2.5 rounded-t-[2px] shadow-sm" 
             style="height: {{ ($c / $max) * 100 }}%" 
             title="Вуглеводи: {{ $c }}г"></div>
    @else
        <span class="text-gray-500 text-[10px] italic">немає даних</span>
    @endif
</div>